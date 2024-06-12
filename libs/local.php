<?php

declare(strict_types=1);

trait PVsurplusLocalLib
{
    private function GetFormStatus()
    {
        $formStatus = $this->GetCommonFormStatus();

        return $formStatus;
    }

    public static $STATUS_INVALID = 0;
    public static $STATUS_VALID = 1;
    public static $STATUS_RETRYABLE = 2;

    private function CheckStatus()
    {
        switch ($this->GetStatus()) {
            case IS_ACTIVE:
                $class = self::$STATUS_VALID;
                break;
            default:
                $class = self::$STATUS_INVALID;
                break;
        }

        return $class;
    }

    // Glättungsverfahren
    public static $METHOD_UNMODIFIED = 0;
    public static $METHOD_SIMPLE_MOVING_AVERAGE = 1;
    public static $METHOD_WEIGHTED_MOVING_AVERAGE = 2;

    // Auslösung
    public static $TRIGGER_UPDATE = 0;
    public static $TRIGGER_CHANGE = 1;
    public static $TRIGGER_CYCLIC = 2;

    // Lade-Priorität
    public static $CHARGE_PRIORITY_NORMAL = 0;
    public static $CHARGE_PRIORITY_HIGH = 1;
    public static $CHARGE_PRIORITY_LOW = 2;
    public static $CHARGE_PRIORITY_NONE = 3;

    // Verwendung des Überschusses
    public static $SURPLUS_USE_GENERAL = 0;
    public static $SURPLUS_USE_EV = 1;

    private function InstallVarProfiles(bool $reInstall = false)
    {
        if ($reInstall) {
            $this->SendDebug(__FUNCTION__, 'reInstall=' . $this->bool2str($reInstall), 0);
        }

        $associations = [
            ['Wert' => false, 'Name' => $this->Translate('no'), 'Farbe' => -1],
            ['Wert' => true, 'Name' => $this->Translate('yes'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('PVsurplus.YesNo', VARIABLETYPE_BOOLEAN, '', 0, 0, 0, 1, '', $associations, $reInstall);

        $associations = [
            ['Wert' => self::$CHARGE_PRIORITY_NORMAL, 'Name' => $this->Translate('Normal'), 'Farbe' => -1],
            ['Wert' => self::$CHARGE_PRIORITY_HIGH, 'Name' => $this->Translate('High'), 'Farbe' => -1],
            ['Wert' => self::$CHARGE_PRIORITY_LOW, 'Name' => $this->Translate('Low'), 'Farbe' => -1],
            ['Wert' => self::$CHARGE_PRIORITY_NONE, 'Name' => $this->Translate('None'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('PVsurplus.ChargePriority', VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, '', $associations, $reInstall);

        $associations = [
            ['Wert' => self::$SURPLUS_USE_GENERAL, 'Name' => $this->Translate('general'), 'Farbe' => -1],
            ['Wert' => self::$SURPLUS_USE_EV, 'Name' => $this->Translate('charge EV'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('PVsurplus.SurplusUse', VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, '', $associations, $reInstall);
    }

    private function MethodMapping()
    {
        return [
            self::$METHOD_UNMODIFIED => [
                'caption' => 'unmodified',
            ],
            self::$METHOD_SIMPLE_MOVING_AVERAGE => [
                'caption' => 'simple moving average',
            ],
            self::$METHOD_WEIGHTED_MOVING_AVERAGE => [
                'caption' => 'weighted moving average',
            ],
        ];
    }

    private function MethodAsString($val)
    {
        $maps = $this->MethodMapping();
        if (isset($maps[$val])) {
            $ret = $this->Translate($maps[$val]['caption']);
        } else {
            $ret = $this->Translate('Unknown methode') . ' ' . $val;
        }
        return $ret;
    }

    private function MethodAsOptions()
    {
        $maps = $this->MethodMapping();
        $opts = [];
        foreach ($maps as $i => $e) {
            $opts[] = [
                'caption' => $e['caption'],
                'value'   => $i,
            ];
        }
        return $opts;
    }

    private function TriggerMapping()
    {
        return [
            self::$TRIGGER_UPDATE => [
                'caption' => 'on update',
            ],
            self::$TRIGGER_CHANGE => [
                'caption' => 'on change',
            ],
            self::$TRIGGER_CYCLIC => [
                'caption' => 'cyclic',
            ],
        ];
    }

    private function TriggerAsString($val)
    {
        $maps = $this->TriggerMapping();
        if (isset($maps[$val])) {
            $ret = $this->Translate($maps[$val]['caption']);
        } else {
            $ret = $this->Translate('Unknown trigger') . ' ' . $val;
        }
        return $ret;
    }

    private function TriggerAsOptions()
    {
        $maps = $this->TriggerMapping();
        $opts = [];
        foreach ($maps as $i => $e) {
            $opts[] = [
                'caption' => $e['caption'],
                'value'   => $i,
            ];
        }
        return $opts;
    }
}
