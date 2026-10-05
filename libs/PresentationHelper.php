<?php

// SPDX-License-Identifier: MIT
// Copyright (c) 2026 Armin Frohwerk

declare(strict_types=1);

/**
 * Hilfsfunktionen für Variablen-Darstellungen (Symcon >= 8.0).
 *
 * Wichtig: In verschachtelten Strukturen (OPTIONS, INTERVALS) sind laut Symcon
 * keine Parameter optional. Deshalb werden hier immer alle Felder gesetzt.
 */
trait MammotionPresentationHelper
{
    /**
     * Wertanzeige mit optionalem Suffix, Nachkommastellen und Intervallen.
     */
    private function PresentValue(string $icon, string $suffix = '', ?int $digits = null, array $intervals = []): array
    {
        $presentation = [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => $icon
        ];
        if ($suffix !== '') {
            $presentation['SUFFIX'] = $suffix;
        }
        if ($digits !== null) {
            $presentation['DIGITS'] = $digits;
        }
        if (count($intervals) > 0) {
            $presentation['INTERVALS_ACTIVE'] = true;
            $presentation['INTERVALS'] = (string) json_encode($intervals, JSON_UNESCAPED_UNICODE);
        }
        return $presentation;
    }

    /**
     * Wertanzeige für Integer-Statuscodes: jeder Code wird als Text mit Farbe und Icon angezeigt.
     * Symcon empfiehlt dafür Intervalle mit IntervalMinValue = IntervalMaxValue.
     *
     * @param array $states [Wert => [Beschriftung, Farbe (-1 = keine), Icon ('' = Standard)]]
     */
    private function PresentStates(string $icon, array $states): array
    {
        $intervals = [];
        foreach ($states as $value => [$caption, $color, $stateIcon]) {
            $intervals[] = $this->Interval((float) $value, (float) $value, 1, '', '', null, $stateIcon, $color, $caption);
        }
        return $this->PresentValue($icon, '', null, $intervals);
    }

    /**
     * Wertanzeige für Boolean-Variablen mit eigenen Texten und Farben.
     */
    private function PresentBool(string $icon, string $falseCaption, int $falseColor, string $trueCaption, int $trueColor): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => $icon,
            'OPTIONS'      => (string) json_encode([
                ['Value' => false, 'Caption' => $falseCaption, 'IconActive' => false, 'IconValue' => '', 'ColorActive' => $falseColor >= 0, 'ColorValue' => max(0, $falseColor)],
                ['Value' => true, 'Caption' => $trueCaption, 'IconActive' => false, 'IconValue' => '', 'ColorActive' => $trueColor >= 0, 'ColorValue' => max(0, $trueColor)]
            ], JSON_UNESCAPED_UNICODE)
        ];
    }

    /**
     * Aufzählung für bedienbare Variablen (nur zusammen mit EnableAction).
     *
     * @param array $options [Wert => [Beschriftung, Icon ('' = keins), Farbe (-1 = keine)]]
     */
    private function PresentEnumeration(string $icon, array $options): array
    {
        $list = [];
        foreach ($options as $value => [$caption, $optionIcon, $color]) {
            $list[] = [
                'Value'      => (int) $value,
                'Caption'    => (string) $caption,
                'IconActive' => $optionIcon !== '',
                'IconValue'  => (string) $optionIcon,
                'Color'      => (int) $color
            ];
        }
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => $icon,
            'DISPLAY'      => 2,
            'LAYOUT'       => 1,
            'OPTIONS'      => (string) json_encode($list, JSON_UNESCAPED_UNICODE)
        ];
    }

    /**
     * Datum und Uhrzeit für Unix-Zeitstempel.
     */
    private function PresentDateTime(bool $seconds = false): array
    {
        return [
            'PRESENTATION'    => VARIABLE_PRESENTATION_DATE_TIME,
            'DATE'            => 1,
            'MONTH_TEXT'      => false,
            'DAY_OF_THE_WEEK' => false,
            'TIME'            => $seconds ? 2 : 1
        ];
    }

    /**
     * Ein vollständig belegtes Intervall für die Wertanzeige.
     */
    private function Interval(float $min, float $max, float $factor, string $suffix, string $prefix, ?int $digits, string $icon, int $color, string $constant = ''): array
    {
        return [
            'IntervalMinValue' => $min,
            'IntervalMaxValue' => $max,
            'ConstantActive'   => $constant !== '',
            'ConstantValue'    => $constant,
            'ConversionFactor' => $factor,
            'PrefixActive'     => $prefix !== '',
            'PrefixValue'      => $prefix,
            'SuffixActive'     => $suffix !== '',
            'SuffixValue'      => $suffix,
            'DigitsActive'     => $digits !== null,
            'DigitsValue'      => $digits ?? 0,
            'IconActive'       => $icon !== '',
            'IconValue'        => $icon,
            'ColorActive'      => $color >= 0,
            'Color'            => max(0, $color)
        ];
    }

    /**
     * Entfernt Variablenprofile früherer Versionen, sofern keine Variable sie mehr verwendet.
     */
    private function RemoveUnusedProfiles(array $profiles): void
    {
        $profiles = array_values(array_filter($profiles, 'IPS_VariableProfileExists'));
        if (count($profiles) === 0) {
            return;
        }
        $used = [];
        foreach (IPS_GetVariableList() as $variableID) {
            $variable = IPS_GetVariable($variableID);
            $used[(string) ($variable['VariableProfile'] ?? '')] = true;
            $used[(string) ($variable['VariableCustomProfile'] ?? '')] = true;
        }
        foreach ($profiles as $profile) {
            if (!isset($used[$profile])) {
                IPS_DeleteVariableProfile($profile);
            }
        }
    }
}
