<?php
namespace Core2\Classes\Table;
use Core2\Classes\Table\Trait\Attributes;

require_once 'Trait/Attributes.php';

/**
 *
 */
class Cell {

    use Attributes;

    private $value = '';

    /**
     * @param mixed $value
     */
    public function __construct(mixed $value) {
        $this->value = $value;
    }


    /**
     * @return string
     */
    public function __toString() {
        return (string)$this->value;
    }


    /**
     * @param mixed $value
     */
    public function setValue(mixed $value): void {
        $this->value = $value;
    }


    /**
     * @return mixed
     */
    public function getValue(): mixed {
        return $this->value;
    }


    /**
     * @return string
     */
    public function val(): mixed {
        return $this->value;
    }


    /**
     * Преобразование в массив
     * @return array
     */
    public function toArray(): array {

        $data = [
            'value' => $this->value,
        ];

        if ( ! empty($this->attr)) {
            $data['attr'] = $this->attr;
        }

        return $data;
    }
}