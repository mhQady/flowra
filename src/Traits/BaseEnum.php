<?php

namespace Flowra\Traits;

use Throwable;

trait BaseEnum
{
    public static function mapForSelect(): array
    {
        $baseName = \Str::of(class_basename(self::class))->beforeLast('States')->snake()->toString();


        return array_map(function ($status) use ($baseName) {
            return [
                'value' => $status->value,
                'label' => __('enum.'.$baseName.'.'.strtolower($status->name)),
            ];
        }, self::cases());
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function keys(): array
    {
        return array_column(self::cases(), 'name');
    }

    /**
     * @throws Throwable
     */
    public static function value($key): ?string
    {
        $key = strtoupper($key);

        if (!in_array($key, self::keys())) {
            throw new \Exception('Invalid key');
        }

        foreach (self::cases() as $case) {
            if ($case->name == $key) {
                return $case->value;
            }
        }

        return null;
    }

    /**
     * @throws Throwable
     */
    public static function key($type)
    {
        if (!in_array($type, self::values())) {
            throw new \Exception('Invalid type');
        }

        foreach (self::cases() as $case) {
            if ($case->value == $type) {
                return strtolower($case->name);
            }
        }

        return null;
    }

    public function label(?string $prefix = null, ?array $replace = null)
    {
        $prefix = $prefix ?? \Str::of(class_basename(self::class))->replaceLast('Enum', '')->snake()->value();

        if ($replace) {

            return trans('enum.'.$prefix.'.'.strtolower($this->name), $replace);
        }

        return trans('enum.'.$prefix.'.'.strtolower($this->name));
    }

    public static function random(): self
    {
        return self::cases()[rand(0, count(self::cases()) - 1)];
    }

    public function mapValue(): array
    {
        $baseName = \Str::of(class_basename(self::class))->beforeLast('Enum')->snake()->toString();

        return [
            'value' => $this->value,
            'label' => __('enum.'.$baseName.'.'.strtolower($this->name)),
        ];
    }
}
