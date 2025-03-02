<?php

namespace PhpOffice\PhpSpreadsheet\Cell;

use DateTime;
use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\Calculation\Information\ExcelError;
use PhpOffice\PhpSpreadsheet\Collection\Cells;
use PhpOffice\PhpSpreadsheet\Exception;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as SharedDate;
use PhpOffice\PhpSpreadsheet\Style\ConditionalFormatting\CellStyleAssessor;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

class Cell
{
    /**
     * Value binder to use.
     *
     * @var IValueBinder
     */
    private static $valueBinder;

    /**
     * Value of the cell.
     *
     * @var mixed
     */
    private $value;

    /**
     *    Calculated value of the cell (used for caching)
     *    This returns the value last calculated by MS Excel or whichever spreadsheet program was used to
     *        create the original spreadsheet file.
     *    Note that this value is not guaranteed to reflect the actual calculated value because it is
     *        possible that auto-calculation was disabled in the original spreadsheet, and underlying data
     *        values used by the formula have changed since it was last calculated.
     *
     * @var mixed
     */
    private $calculatedValue;

    /**
     * Type of the cell data.
     *
     * @var string
     */
    private $dataType;

    /**
     * Collection of cells.
     *
     * @var Cells
     */
    private $parent;

    /**
     * Index to cellXf.
     *
     * @var int
     */
    private $xfIndex = 0;

    /**
     * Attributes of the formula.
     */
    private $formulaAttributes;

    /**
     * Update the cell into the cell collection.
     *
     * @return $this
     */
    public function updateInCollection(): self
    {
        $this->parent->update($this);

        return $this;
    }

    public function detach(): void
    {
        // @phpstan-ignore-next-line
        $this->parent = null;
    }

    public function attach(Cells $parent): void
    {
        $this->parent = $parent;
    }

    /**
     * Create a new Cell.
     *
     * @param mixed $value
     * @param string $dataType
     */
    public function __construct($value, $dataType, Worksheet $worksheet)
    {
        // Initialise cell value
        $this->value = $value;

        // Set worksheet cache
        $this->parent = $worksheet->getCellCollection();

        // Set datatype?
        if ($dataType !== null) {
            if ($dataType == DataType::TYPE_STRING2) {
                $dataType = DataType::TYPE_STRING;
            }
            $this->dataType = $dataType;
        } elseif (!self::getValueBinder()->bindValue($this, $value)) {
            throw new Exception('Value could not be bound to cell.');
        }
    }

    /**
     * Get cell coordinate column.
     *
     * @return string
     */
    public function getColumn()
    {
        return $this->parent->getCurrentColumn();
    }

    /**
     * Get cell coordinate row.
     *
     * @return int
     */
    public function getRow()
    {
        return $this->parent->getCurrentRow();
    }

    /**
     * Get cell coordinate.
     *
     * @return string
     */
    public function getCoordinate()
    {
        try {
            $coordinate = $this->parent->getCurrentCoordinate();
        } catch (Throwable $e) {
            $coordinate = null;
        }
        if ($coordinate === null) {
            throw new Exception('Coordinate no longer exists');
        }

        return $coordinate;
    }

    /**
     * Get cell value.
     *
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Get cell value with formatting.
     *
     * @return string
     */
    public function getFormattedValue()
    {
        return (string) NumberFormat::toFormattedString(
            $this->getCalculatedValue(),
            $this->getStyle()
                ->getNumberFormat()->getFormatCode()
        );
    }

    /**
     * Set cell value.
     *
     *    Sets the value for a cell, automatically determining the datatype using the value binder
     *
     * @param mixed $value Value
     *
     * @return $this
     */
    public function setValue($value)
    {
        if (!self::getValueBinder()->bindValue($this, $value)) {
            throw new Exception('Value could not be bound to cell.');
        }

        return $this;
    }

    /**
     * Set the value for a cell, with the explicit data type passed to the method (bypassing any use of the value binder).
     *
     * @param mixed $value Value
     * @param string $dataType Explicit data type, see DataType::TYPE_*
     *
     * @return Cell
     */
    public function setValueExplicit($value, $dataType)
    {
        // set the value according to data type
        switch ($dataType) {
            case DataType::TYPE_NULL:
                $this->value = $value;

                break;
            case DataType::TYPE_STRING2:
                $dataType = DataType::TYPE_STRING;
                // no break
            case DataType::TYPE_STRING:
                // Synonym for string
            case DataType::TYPE_INLINE:
                // Rich text
                $this->value = DataType::checkString($value);

                break;
            case DataType::TYPE_NUMERIC:
                if (is_string($value) && !is_numeric($value)) {
                    throw new Exception('Invalid numeric value for datatype Numeric');
                }
                $this->value = 0 + $value;

                break;
            case DataType::TYPE_FORMULA:
                $this->value = (string) $value;

                break;
            case DataType::TYPE_BOOL:
                $this->value = (bool) $value;

                break;
            case DataType::TYPE_ISO_DATE:
                if (!is_string($value)) {
                    throw new Exception('Non-string supplied for datatype Date');
                }
                $date = new DateTime($value);
                $newValue = SharedDate::PHPToExcel($date);
                if ($newValue === false) {
                    throw new Exception("Invalid string $value supplied for datatype Date");
                }
                if (preg_match('/^\\d\\d:\\d\\d:\\d\\d/', $value) == 1) {
                    $newValue = fmod($newValue, 1.0);
                }
                $this->value = $newValue;
                $dataType = DataType::TYPE_NUMERIC;

                break;
            case DataType::TYPE_ERROR:
                $this->value = DataType::checkErrorCode($value);

                break;
            default:
                throw new Exception('Invalid datatype: ' . $dataType);

                break;
        }

        // set the datatype
        $this->dataType = $dataType;

        return $this->updateInCollection();
    }

    /**
     * Get calculated cell value.
     *
     * @param bool $resetLog Whether the calculation engine logger should be reset or not
     *
     * @return mixed
     */
    public function getCalculatedValue($resetLog = true)
    {
        if ($this->dataType === DataType::TYPE_FORMULA) {
            try {
                $index = $this->getWorksheet()->getParent()->getActiveSheetIndex();
                $selected = $this->getWorksheet()->getSelectedCells();
                $result = Calculation::getInstance(
                    $this->getWorksheet()->getParent()
                )->calculateCellValue($this, $resetLog);
                $this->getWorksheet()->setSelectedCells($selected);
                $this->getWorksheet()->getParent()->setActiveSheetIndex($index);
                //    We don't yet handle array returns
                if (is_array($result)) {
                    while (is_array($result)) {
                        $result = array_shift($result);
                    }
                }
            } catch (Exception $ex) {
                if (($ex->getMessage() === 'Unable to access External Workbook') && ($this->calculatedValue !== null)) {
                    return $this->calculatedValue; // Fallback for calculations referencing external files.
                } elseif (preg_match('/[Uu]ndefined (name|offset: 2|array key 2)/', $ex->getMessage()) === 1) {
                    return ExcelError::NAME();
                }

                throw new \PhpOffice\PhpSpreadsheet\Calculation\Exception(
                    $this->getWorksheet()->getTitle() . '!' . $this->getCoordinate() . ' -> ' . $ex->getMessage()
                );
            }

            if ($result === '#Not Yet Implemented') {
                return $this->calculatedValue; // Fallback if calculation engine does not support the formula.
            }

            return $result;
        } elseif ($this->value instanceof RichText) {
            return $this->value->getPlainText();
        }

        return $this->value;
    }

    /**
     * Set old calculated value (cached).
     *
     * @param mixed $originalValue Value
     *
     * @return Cell
     */
    public function setCalculatedValue($originalValue)
    {
        if ($originalValue !== null) {
            $this->calculatedValue = (is_numeric($originalValue)) ? (float) $originalValue : $originalValue;
        }

        return $this->updateInCollection();
    }

    /**
     *    Get old calculated value (cached)
     *    This returns the value last calculated by MS Excel or whichever spreadsheet program was used to
     *        create the original spreadsheet file.
     *    Note that this value is not guaranteed to reflect the actual calculated value because it is
     *        possible that auto-calculation was disabled in the original spreadsheet, and underlying data
     *        values used by the formula have changed since it was last calculated.
     *
     * @return mixed
     */
    public function getOldCalculatedValue()
    {
        return $this->calculatedValue;
    }

    /**
     * Get cell data type.
     *
     * @return string
     */
    public function getDataType()
    {
        return $this->dataType;
    }

    /**
     * Set cell data type.
     *
     * @param string $dataType see DataType::TYPE_*
     *
     * @return Cell
     */
    public function setDataType($dataType)
    {
        if ($dataType == DataType::TYPE_STRING2) {
            $dataType = DataType::TYPE_STRING;
        }
        $this->dataType = $dataType;

        return $this->updateInCollection();
    }

    /**
     * Identify if the cell contains a formula.
     */
    public function isFormula(): bool
    {
        return $this->dataType === DataType::TYPE_FORMULA && $this->getStyle()->getQuotePrefix() === false;
    }

    /**
     *    Does this cell contain Data validation rules?
     */
    public function hasDataValidation(): bool
    {
        if (!isset($this->parent)) {
            throw new Exception('Cannot check for data validation when cell is not bound to a worksheet');
        }

        return $this->getWorksheet()->dataValidationExists($this->getCoordinate());
    }

    /**
     * Get Data validation rules.
     *
     * @return DataValidation
     */
    public function getDataValidation()
    {
        if (!isset($this->parent)) {
            throw new Exception('Cannot get data validation for cell that is not bound to a worksheet');
        }

        return $this->getWorksheet()->getDataValidation($this->getCoordinate());
    }

    /**
     * Set Data validation rules.
     */
    public function setDataValidation(?DataValidation $dataValidation = null): self
    {
        if (!isset($this->parent)) {
            throw new Exception('Cannot set data validation for cell that is not bound to a worksheet');
        }

        $this->getWorksheet()->setDataValidation($this->getCoordinate(), $dataValidation);

        return $this->updateInCollection();
    }

    /**
     * Does this cell contain valid value?
     *
     * @return bool
     */
    public function hasValidValue()
    {
        $validator = new DataValidator();

        return $validator->isValid($this);
    }

    /**
     * Does this cell contain a Hyperlink?
     *
     * @return bool
     */
    public function hasHyperlink()
    {
        if (!isset($this->parent)) {
            throw new Exception('Cannot check for hyperlink when cell is not bound to a worksheet');
        }

        return $this->getWorksheet()->hyperlinkExists($this->getCoordinate());
    }

    /**
     * Get Hyperlink.
     *
     * @return Hyperlink
     */
    public function getHyperlink()
    {
        if (!isset($this->parent)) {
            throw new Exception('Cannot get hyperlink for cell that is not bound to a worksheet');
        }

        return $this->getWorksheet()->getHyperlink($this->getCoordinate());
    }

    /**
     * Set Hyperlink.
     *
     * @return Cell
     */
    public function setHyperlink(?Hyperlink $hyperlink = null)
    {
        if (!isset($this->parent)) {
            throw new Exception('Cannot set hyperlink for cell that is not bound to a worksheet');
        }

        $this->getWorksheet()->setHyperlink($this->getCoordinate(), $hyperlink);

        return $this->updateInCollection();
    }

    /**
     * Get cell collection.
     *
     * @return Cells
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * Get parent worksheet.
     *
     * @return Worksheet
     */
    public function getWorksheet()
    {
        try {
            $worksheet = $this->parent->getParent();
        } catch (Throwable $e) {
            $worksheet = null;
        }

        if ($worksheet === null) {
            throw new Exception('Worksheet no longer exists');
        }

        return $worksheet;
    }

    /**
     * Is this cell in a merge range.
     *
     * @return bool
     */
    public function isInMergeRange()
    {
        return (bool) $this->getMergeRange();
    }

    /**
     * Is this cell the master (top left cell) in a merge range (that holds the actual data value).
     *
     * @return bool
     */
    public function isMergeRangeValueCell()
    {
        if ($mergeRange = $this->getMergeRange()) {
            $mergeRange = Coordinate::splitRange($mergeRange);
            [$startCell] = $mergeRange[0];
            if ($this->getCoordinate() === $startCell) {
                return true;
            }
        }

        return false;
    }

    /**
     * If this cell is in a merge range, then return the range.
     *
     * @return false|string
     */
    public function getMergeRange()
    {
        foreach ($this->getWorksheet()->getMergeCells() as $mergeRange) {
            if ($this->isInRange($mergeRange)) {
                return $mergeRange;
            }
        }

        return false;
    }

    /**
     * Get cell style.
     */
    public function getStyle(): Style
    {
        return $this->getWorksheet()->getStyle($this->getCoordinate());
    }

    /**
     * Get cell style.
     */
    public function getAppliedStyle(): Style
    {
        if ($this->getWorksheet()->conditionalStylesExists($this->getCoordinate()) === false) {
            return $this->getStyle();
        }
        $range = $this->getWorksheet()->getConditionalRange($this->getCoordinate());
        if ($range === null) {
            return $this->getStyle();
        }

        $matcher = new CellStyleAssessor($this, $range);

        return $matcher->matchConditions($this->getWorksheet()->getConditionalStyles($this->getCoordinate()));
    }

    /**
     * Re-bind parent.
     *
     * @return Cell
     */
    public function rebindParent(Worksheet $parent)
    {
        $this->parent = $parent->getCellCollection();

        return $this->updateInCollection();
    }

    /**
     *    Is cell in a specific range?
     *
     * @param string $range Cell range (e.g. A1:A1)
     *
     * @return bool
     */
    public function isInRange($range)
    {
        [$rangeStart, $rangeEnd] = Coordinate::rangeBoundaries($range);

        // Translate properties
        $myColumn = Coordinate::columnIndexFromString($this->getColumn());
        $myRow = $this->getRow();

        // Verify if cell is in range
        return ($rangeStart[0] <= $myColumn) && ($rangeEnd[0] >= $myColumn) &&
                ($rangeStart[1] <= $myRow) && ($rangeEnd[1] >= $myRow);
    }

    /**
     * Compare 2 cells.
     *
     * @param Cell $a Cell a
     * @param Cell $b Cell b
     *
     * @return int Result of comparison (always -1 or 1, never zero!)
     */
    public static function compareCells(self $a, self $b)
    {
        if ($a->getRow() < $b->getRow()) {
            return -1;
        } elseif ($a->getRow() > $b->getRow()) {
            return 1;
        } elseif (Coordinate::columnIndexFromString($a->getColumn()) < Coordinate::columnIndexFromString($b->getColumn())) {
            return -1;
        }

        return 1;
    }

    /**
     * Get value binder to use.
     *
     * @return IValueBinder
     */
    public static function getValueBinder()
    {
        if (self::$valueBinder === null) {
            self::$valueBinder = new DefaultValueBinder();
        }

        return self::$valueBinder;
    }

    /**
     * Set value binder to use.
     */
    public static function setValueBinder(IValueBinder $binder): void
    {
        self::$valueBinder = $binder;
    }

    /**
     * Implement PHP __clone to create a deep clone, not just a shallow copy.
     */
    public function __clone()
    {
        $vars = get_object_vars($this);
        foreach ($vars as $key => $value) {
            if ((is_object($value)) && ($key != 'parent')) {
                $this->$key = clone $value;
            } else {
                $this->$key = $value;
            }
        }
    }

    /**
     * Get index to cellXf.
     *
     * @return int
     */
    public function getXfIndex()
    {
        return $this->xfIndex;
    }

    /**
     * Set index to cellXf.
     *
     * @param int $indexValue
     *
     * @return Cell
     */
    public function setXfIndex($indexValue)
    {
        $this->xfIndex = $indexValue;

        return $this->updateInCollection();
    }

    /**
     * Set the formula attributes.
     *
     * @param mixed $attributes
     *
     * @return $this
     */
    public function setFormulaAttributes($attributes)
    {
        $this->formulaAttributes = $attributes;

        return $this;
    }

    /**
     * Get the formula attributes.
     */
    public function getFormulaAttributes()
    {
        return $this->formulaAttributes;
    }

    /**
     * Convert to string.
     *
     * @return string
     */
    public function __toString()
    {
        return (string) $this->getValue();
    }
}
