<?php
namespace Core2\Classes\Table;
use Core2\Classes\Table\Trait\Attributes;

require_once 'Cell.php';


/**
 *
 */
class Record implements \Iterator {

    use Attributes;

    private array $cells = [];


    /**
     * Row constructor.
     * @param array $record
     */
    public function __construct(array $record) {

        foreach ($record as $key => $cell) {
            $this->cells[$key] = new Cell($cell);
        }
    }


    /**
     * Get cell value
     * @param string $field
     * @return string|int|float|null
     */
    public function __get(string $field) {

        if ( ! array_key_exists($field, $this->cells)) {
            $this->cells[$field] = new Cell('');
        }

        return $this->cells[$field]->getValue();
    }


    /**
     * Set value in cell
     * @param string $field
     * @param mixed  $value
     */
    public function __set(string $field, mixed $value) {

        if (array_key_exists($field, $this->cells)) {
            $this->cells[$field]->setValue($value);
        } else {
            $this->cells[$field] = new Cell($value);
        }
    }


    /**
     * Check cell
     * @param string $field
     * @return bool
     */
    public function __isset(string $field) {
        return isset($this->cells[$field]);
    }


    /**
     * @param string $field
     * @return Cell
     */
    public function cell(string $field): Cell {

        if ( ! array_key_exists($field, $this->cells)) {
            $this->cells[$field] = new Cell('');
        }

        return $this->cells[$field];
    }


    /**
     * @return void
     */
    public function rewind(): void {
        reset($this->cells);
    }


    /**
     * @return mixed
     */
    public function key(): mixed {
        return key($this->cells);
    }


    /**
     * @return mixed
     */
    public function current(): mixed {
        return current($this->cells)->getValue();
    }


    /**
     * @return bool
     */
    public function valid(): bool {
        return key($this->cells) !== null;
    }


    /**
     * @return void
     */
    public function next(): void {
        next($this->cells);
    }


    /**
     * Преобразование в массив
     * @return array
     */
    public function toArray(): array {

        $cells = [];

        if ( ! empty($this->cells)) {
            foreach ($this->cells as $field => $cell) {
                if ($cell instanceof Cell) {
                    $cells[$field] = $cell->toArray();
                }
            }
        }

        $data = [
            'cells' => $cells,
        ];

        if ( ! empty($this->attr)) {
            $data['attr'] = $this->attr;
        }

        return $data;
    }
}