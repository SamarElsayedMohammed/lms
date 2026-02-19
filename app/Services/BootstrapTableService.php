<?php

declare(strict_types=1);

namespace App\Services;

class BootstrapTableService
{
    private static string $defaultClasses = 'btn btn-action-icon';

    /**
     * @param string $iconClass
     * @param string $url
     * @param array<string> $customClass
     * @param array<string, string|null> $customAttributes
     * @param string $iconText
     * @return string
     */
    public static function button(
        string $iconClass,
        string $url,
        array $customClass = [],
        array $customAttributes = [],
        string $iconText = '',
    ): string {
        // Filter out empty/null classes
        $customClass = array_filter($customClass, static fn($c) => $c !== null && $c !== '');
        $customClassStr = implode(' ', $customClass);
        $class = trim(self::$defaultClasses . ' ' . $customClassStr);

        $attributes = '';
        foreach ($customAttributes as $key => $value) {
            if ($value !== null && $value !== '') {
                $attributes .= $key . '="' . e($value) . '" ';
            }
        }

        return (
            '<a href="'
            . e($url)
            . '" class="'
            . $class
            . '" '
            . trim($attributes)
            . '>'
            . '<i class="'
            . e($iconClass)
            . '"></i>'
            . ($iconText !== '' ? '<span class="btn-text">' . e($iconText) . '</span>' : '')
            . '</a>'
        );
    }

    /**
     * @param string $iconClass
     * @param array<array{url: string, icon: string, text: string}> $dropdownItems
     * @param array<string> $customClass
     * @param array<string, string|null> $customAttributes
     * @return string
     */
    public static function dropdown(
        string $iconClass,
        array $dropdownItems,
        array $customClass = [],
        array $customAttributes = [],
    ): string {
        $customClass = array_filter($customClass, static fn($c) => $c !== null && $c !== '');
        $customClassStr = implode(' ', $customClass);
        $class = trim(self::$defaultClasses . ' dropdown ' . $customClassStr);

        $attributes = '';
        foreach ($customAttributes as $key => $value) {
            if ($value !== null && $value !== '') {
                $attributes .= $key . '="' . e($value) . '" ';
            }
        }

        $dropdown = '<div class="' . $class . '" ' . trim($attributes) . '>';
        $dropdown .= '<button class="btn btn-action-icon btn-secondary dropdown-toggle" type="button" data-toggle="dropdown" aria-expanded="false">';
        $dropdown .= '<i class="' . e($iconClass) . '"></i>';
        $dropdown .= '</button>';
        $dropdown .= '<ul class="dropdown-menu dropdown-menu-right">';

        foreach ($dropdownItems as $item) {
            $dropdown .=
                '<li><a class="dropdown-item" href="'
                . e($item['url'])
                . '">'
                . '<i class="'
                . e($item['icon'])
                . '"></i> '
                . e($item['text'])
                . '</a></li>';
        }

        $dropdown .= '</ul>';
        $dropdown .= '</div>';

        return $dropdown;
    }

    /**
     * Edit button - primary color with edit icon
     */
    public static function editButton(
        string $url,
        bool $modal = false,
        null|string $dataBsTarget = '#editModal',
        null|string $customClass = null,
        null|string $id = null,
        string $iconClass = 'fa fa-edit',
        null|string $onClick = null,
    ): string {
        $classes = ['btn-primary', 'edit-data'];
        if ($customClass !== null && $customClass !== '') {
            $classes[] = $customClass;
        }

        $customAttributes = [
            'title' => trans('Edit'),
        ];

        if ($modal) {
            $customAttributes = [
                'title' => trans('Edit'),
                'data-target' => $dataBsTarget,
                'data-toggle' => 'modal',
                'id' => $id,
                'onclick' => $onClick,
            ];
            $classes[] = 'edit_btn';
            $classes[] = 'set-form-url';
        }

        return self::button($iconClass, $url, $classes, $customAttributes);
    }

    /**
     * Delete button - danger color with trash icon
     */
    public static function deleteButton(
        string $url,
        null|string $id = null,
        null|string $dataId = null,
        null|string $dataCategory = null,
        null|string $customClass = null,
    ): string {
        $classes = ['btn-danger', 'delete-form'];
        if ($customClass !== null && $customClass !== '') {
            $classes[] = $customClass;
        }

        $customAttributes = [
            'title' => trans('Delete'),
            'id' => $id,
            'data-id' => $dataId,
            'data-category' => $dataCategory,
        ];

        return self::button('fas fa-trash', $url, $classes, $customAttributes);
    }

    /**
     * Restore button - success color with rotate icon
     */
    public static function restoreButton(string $url, string $title = 'Restore'): string
    {
        return self::button('fa fa-rotate', $url, ['btn-success', 'restore-data'], ['title' => trans($title)]);
    }

    /**
     * Permanent delete button - danger color with times icon
     */
    public static function trashButton(string $url): string
    {
        return self::button('fa fa-times', $url, ['btn-danger', 'trash-data'], ['title' => trans('Delete Permanent')]);
    }

    /**
     * Option button - with gear icon and text
     */
    public static function optionButton(string $url): string
    {
        return self::button(
            'bi bi-gear',
            $url,
            ['btn-secondary', 'btn-option'],
            ['title' => trans('View Option Data')],
            'Options',
        );
    }

    /**
     * View button - info color with eye icon, opens in new tab
     */
    public static function viewButton(string $url): string
    {
        return self::button('fa fa-eye', $url, ['btn-info'], ['title' => trans('View Page'), 'target' => '_blank']);
    }
}
