<?php

namespace allomambo\fort\helpers;

use allomambo\fort\Plugin;
use craft\base\ElementInterface;
use craft\helpers\Cp;

/**
 * Control Panel HTML helpers that work on Craft 4 and 5.
 */
final class FortCp
{
    /**
     * Render a compact element chip/row for Fort CP tables.
     */
    public static function elementChipHtml(?ElementInterface $element): string
    {
        if ($element === null) {
            return '';
        }

        if (Plugin::isCraft5() && method_exists(Cp::class, 'elementChipHtml')) {
            return Cp::elementChipHtml($element, [
                'size' => Cp::ELEMENT_SIZE_SMALL,
                'showThumb' => true,
                'showLabel' => true,
                'showStatus' => false,
                'hyperlink' => true,
                'attributes' => ['class' => ['chromeless']],
            ]);
        }

        return Cp::elementHtml(
            $element,
            'index',
            Cp::ELEMENT_SIZE_SMALL,
            null,
            false,
            true,
            true
        );
    }
}
