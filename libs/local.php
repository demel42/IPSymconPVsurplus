<?php

declare(strict_types=1);

trait SmoothValueProgressionLocalLib
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

    public static $MODE_UNMODIFIED = 0;
    public static $MODE_AVERAGE = 1;
    public static $MODE_MEDIAN = 2;
    public static $MODE_SIMPLE_MOVING_AVERAGE = 3;
    public static $MODE_WEIGHTED_MOVING_AVERAGE = 4;
    public static $MODE_EXPONENTIAL_MOVING_AVERAGE = 5;

    private function InstallVarProfiles(bool $reInstall = false)
    {
        if ($reInstall) {
            $this->SendDebug(__FUNCTION__, 'reInstall=' . $this->bool2str($reInstall), 0);
        }
    }

    private function ModeMapping()
    {
        return [
            self::$MODE_UNMODIFIED => [
                'caption' => 'unmodified',
            ],
            self::$MODE_AVERAGE => [
                'caption' => 'Average',
            ],
            self::$MODE_MEDIAN => [
                'caption' => 'Median',
            ],
            self::$MODE_SIMPLE_MOVING_AVERAGE => [
                'caption' => 'simple moving average',
            ],
            self::$MODE_WEIGHTED_MOVING_AVERAGE => [
                'caption' => 'weighted moving average',
            ],
            /*
            self::$MODE_EXPONENTIAL_MOVING_AVERAGE => [
                'caption' => 'exponential moving average',
            ],
             */
        ];
    }

    private function ModeAsString($mode)
    {
        $maps = $this->ModeMapping();
        if (isset($maps[$mode])) {
            $ret = $this->Translate($maps[$mode]['caption']);
        } else {
            $ret = $this->Translate('Unknown mode') . ' ' . $mode;
        }
        return $ret;
    }

    private function ModeAsOptions()
    {
        $maps = $this->ModeMapping();
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
