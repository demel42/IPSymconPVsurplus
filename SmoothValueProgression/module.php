<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class SmoothValueProgression extends IPSModule
{
    use SmoothValueProgression\StubsCommonLib;
    use SmoothValueProgressionLocalLib;

    private static $semaphoreTM = 5 * 1000;

    private $SemaphoreID;

    public function __construct(string $InstanceID)
    {
        parent::__construct($InstanceID);

        $this->CommonConstruct(__DIR__);
        $this->SemaphoreID = __CLASS__ . '_' . $InstanceID;
    }

    public function __destruct()
    {
        $this->CommonDestruct();
    }

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyInteger('source_varID', 0);

        $this->RegisterPropertyInteger('mode', self::$MODE_WEIGHTED_MOVING_AVERAGE);
        $this->RegisterPropertyInteger('interval', 60);
        $this->RegisterPropertyInteger('count', 10);

        $this->RegisterPropertyBoolean('log_destination', true);

        $this->RegisterAttributeString('UpdateInfo', json_encode([]));
        $this->RegisterAttributeString('ModuleStats', json_encode([]));

        $this->InstallVarProfiles(false);

        $this->RegisterTimer('UpdateData', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateDataByTimer", "");');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        parent::MessageSink($timestamp, $senderID, $message, $data);

        if ($message == IPS_KERNELMESSAGE && $data[0] == KR_READY) {
            $mode = $this->ReadPropertyInteger('mode');
            switch ($mode) {
                case self::$MODE_AVERAGE:
                case self::$MODE_MEDIAN:
                    $this->SetUpdateInterval();
                    break;
                case self::$MODE_UNMODIFIED:
                case self::$MODE_SIMPLE_MOVING_AVERAGE:
                case self::$MODE_WEIGHTED_MOVING_AVERAGE:
                case self::$MODE_EXPONENTIAL_MOVING_AVERAGE:
                    $source_varID = $this->ReadPropertyInteger('source_varID');
                    if (IPS_VariableExists($source_varID)) {
                        $this->RegisterMessage($source_varID, VM_UPDATE);
                    }
                    break;
                default:
                    break;
            }
        }

        if (IPS_GetKernelRunlevel() == KR_READY && $message == VM_UPDATE && $data[1] == true /* changed */) {
            $this->SendDebug(__FUNCTION__, 'timestamp=' . $timestamp . ', senderID=' . $senderID . ', message=' . $message . ', data=' . print_r($data, true), 0);
            $this->UpdateDataByEvent($data[0], $data[2], $data[1]);
        }
    }

    private function CheckModuleConfiguration()
    {
        $r = [];

        $source_varID = $this->ReadPropertyInteger('source_varID');
        if (IPS_VariableExists($source_varID) == false) {
            $this->SendDebug(__FUNCTION__, '"source_varID" is needed', 0);
            $r[] = $this->Translate('Source variable must be specified');
        } else {
            $archivID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
            $loggingStatus = AC_GetLoggingStatus($archivID, $source_varID);
            $aggregationType = AC_GetAggregationType($archivID, $source_varID);
            if ($loggingStatus == false || $aggregationType != 0 /* Standard */) {
                $this->SendDebug(__FUNCTION__, '"source_varID" must be logged as standard', 0);
                $r[] = $this->Translate('Source variable must be logged with standard aggregation');
            }
        }

        return $r;
    }

    private function CheckModuleUpdate(array $oldInfo, array $newInfo)
    {
        $r = [];

        return $r;
    }

    private function CompleteModuleUpdate(array $oldInfo, array $newInfo)
    {
        return '';
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $propertyNames = ['source_varID'];
        $this->MaintainReferences($propertyNames);

        $this->UnregisterMessages([VM_UPDATE]);

        if ($this->CheckPrerequisites() != false) {
            $this->MaintainTimer('UpdateData', 0);
            $this->MaintainStatus(self::$IS_INVALIDPREREQUISITES);
            return;
        }

        if ($this->CheckUpdate() != false) {
            $this->MaintainTimer('UpdateData', 0);
            $this->MaintainStatus(self::$IS_UPDATEUNCOMPLETED);
            return;
        }

        if ($this->CheckConfiguration() != false) {
            $this->MaintainTimer('UpdateData', 0);
            $this->MaintainStatus(self::$IS_INVALIDCONFIG);
            return;
        }

        $vpos = 1;

        $source_varID = $this->ReadPropertyInteger('source_varID');
        if (IPS_VariableExists($source_varID)) {
            $var = IPS_GetVariable($source_varID);
            $variableType = $var['VariableType'];
            $variableProfile = $var['VariableProfile'];
            $variableCustomProfile = $var['VariableCustomProfile'];

            $mode = $this->ReadPropertyInteger('mode');
            $this->MaintainVariable('Destination', $this->ModeAsString($mode), $variableType, $variableProfile, $vpos++, true);

            $varID = $this->GetIDForIdent('Destination');
            IPS_SetVariableCustomProfile($varID, $variableCustomProfile);

            $log_destination = $this->ReadPropertyBoolean('log_destination');
            if ($log_destination) {
                $this->SetVariableLogging('Destination', 0 /* Standard */);
            } else {
                $this->UnsetVariableLogging('Destination');
            }
        }

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->MaintainTimer('UpdateData', 0);
            $this->MaintainStatus(IS_INACTIVE);
            return;
        }

        $this->MaintainStatus(IS_ACTIVE);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $mode = $this->ReadPropertyInteger('mode');
            switch ($mode) {
                case self::$MODE_AVERAGE:
                case self::$MODE_MEDIAN:
                    $this->SetUpdateInterval();
                    break;
                case self::$MODE_UNMODIFIED:
                case self::$MODE_SIMPLE_MOVING_AVERAGE:
                case self::$MODE_WEIGHTED_MOVING_AVERAGE:
                case self::$MODE_EXPONENTIAL_MOVING_AVERAGE:
                    $source_varID = $this->ReadPropertyInteger('source_varID');
                    if (IPS_VariableExists($source_varID)) {
                        $this->RegisterMessage($source_varID, VM_UPDATE);
                    }
                    break;
                default:
                    break;
            }
        }
    }

    private function Use4Mode($mode)
    {
        switch ($mode) {
            case self::$MODE_AVERAGE:
            case self::$MODE_MEDIAN:
                $ret = [
                    'count'    => false,
                    'interval' => true,
                ];
                break;
            case self::$MODE_SIMPLE_MOVING_AVERAGE:
            case self::$MODE_WEIGHTED_MOVING_AVERAGE:
            case self::$MODE_EXPONENTIAL_MOVING_AVERAGE:
                $ret = [
                    'count'    => true,
                    'interval' => false,
                ];
                break;
            case self::$MODE_UNMODIFIED:
            default:
                $ret = [
                    'count'    => false,
                    'interval' => false,
                ];
                break;
        }
        return $ret;
    }

    private function GetFormElements()
    {
        $formElements = $this->GetCommonFormElements('Smooth value progression');

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            return $formElements;
        }

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'module_disable',
            'caption' => 'Disable instance',
        ];

        $formElements[] = [
            'name'               => 'source_varID',
            'type'               => 'SelectVariable',
            'requiredLogging'    => 3, /* Standard-Logging */
            'validVariableTypes' => [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT],
            'width'              => '500px',
            'caption'            => 'Source variable',
        ];

        $mode_use = $this->Use4Mode($this->ReadPropertyInteger('mode'));

        $formElements[] = [
            'type'    => 'RowLayout',
            'items'   => [
                [
                    'type'     => 'Select',
                    'options'  => $this->ModeAsOptions(),
                    'name'     => 'mode',
                    'caption'  => 'Mode',
                    'onChange' => 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateFormField4Mode", $mode);',
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'interval',
                    'suffix'  => 'Seconds',
                    'minimum' => 0,
                    'caption' => 'Interval between calculations',
                    'visible' => $mode_use['interval'],
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'count',
                    'minimum' => 1,
                    'caption' => 'Number of values to be used',
                    'visible' => $mode_use['count'],
                ],
            ],
        ];

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'log_destination',
            'caption' => 'Activate logging of smoothed variable',
        ];

        return $formElements;
    }

    private function GetFormActions()
    {
        $formActions = [];

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            $formActions[] = $this->GetCompleteUpdateFormAction();

            $formActions[] = $this->GetInformationFormAction();
            $formActions[] = $this->GetReferencesFormAction();

            return $formActions;
        }

        $archivID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
        $avars = AC_GetAggregationVariables($archivID, false);

        $source_varID = $this->ReadPropertyInteger('source_varID');
        $start_tm = false;
        $end_tm = false;
        foreach ($avars as $avar) {
            if ($avar['VariableID'] == $source_varID) {
                $r = localtime($avar['FirstTime'], true);
                $start_tm = [
                    'hour'   => $r['tm_hour'],
                    'minute' => $r['tm_min'],
                    'second' => $r['tm_sec'],
                    'month'  => $r['tm_mon'] + 1,
                    'day'    => $r['tm_mday'],
                    'year'   => $r['tm_year'] + 1900,
                ];
                $r = localtime($avar['LastTime'], true);
                $end_tm = [
                    'hour'   => $r['tm_hour'],
                    'minute' => $r['tm_min'],
                    'second' => $r['tm_sec'],
                    'month'  => $r['tm_mon'] + 1,
                    'day'    => $r['tm_mday'],
                    'year'   => $r['tm_year'] + 1900,
                ];
                break;
            }
        }

        $formActions[] = [
            'type'      => 'ExpansionPanel',
            'caption'   => 'Expert area',
            'expanded'  => false,
            'items'     => [
                [
                    'type'    => 'RowLayout',
                    'items'   => [
                        [
                            'type'    => 'SelectDateTime',
                            'name'    => 'start_tm',
                            'caption' => 'Start time',
                            'value'   => json_encode($start_tm),
                        ],
                        [
                            'type'    => 'SelectDateTime',
                            'name'    => 'end_tm',
                            'caption' => 'End time',
                            'value'   => json_encode($end_tm),
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => '(Re-)calculate archive data from the source variable',
                            'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "RecalcDestination", json_encode(["start_tm" => $start_tm, "end_tm" => $end_tm]));',
                            'confirm' => 'This clears the values of destination variable and re-creates it from source variable',
                        ],
                    ],
                ],
                $this->GetInstallVarProfilesFormItem(),
            ],
        ];

        $formActions[] = $this->GetInformationFormAction();
        $formActions[] = $this->GetReferencesFormAction();

        return $formActions;
    }

    private function SetUpdateInterval()
    {
        $mode = $this->ReadPropertyInteger('mode');
        if (in_array($mode, [self::$MODE_AVERAGE, self::$MODE_MEDIAN])) {
            $sec = $this->ReadPropertyInteger('interval');
        } else {
            $sec = 0;
        }
        $this->MaintainTimer('UpdateData', $sec * 1000);
    }

    private function LocalRequestAction($ident, $value)
    {
        $r = true;
        switch ($ident) {
            case 'UpdateDataByTimer':
                $this->UpdateDataByTimer();
                break;
            case 'UpdateFormField4Mode':
                $mode_use = $this->Use4Mode($value);
                $this->UpdateFormField('count', 'visible', $mode_use['count']);
                $this->UpdateFormField('interval', 'visible', $mode_use['interval']);
                break;
            case 'RecalcDestination':
                $this->RecalcDestination($value);
                break;
            default:
                $r = false;
                break;
        }
        return $r;
    }

    public function RequestAction($ident, $value)
    {
        if ($this->LocalRequestAction($ident, $value)) {
            return;
        }
        if ($this->CommonRequestAction($ident, $value)) {
            return;
        }

        if ($this->GetStatus() == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, 'ident=' . $ident . ', value=' . $value, 0);

        $r = false;
        switch ($ident) {
            default:
                $this->SendDebug(__FUNCTION__, 'invalid ident ' . $ident, 0);
                break;
        }
        if ($r) {
            $this->SetValue($ident, $value);
        }
    }

    private function cmp_ac_vals($a, $b)
    {
        return ($a['TimeStamp'] < $b['TimeStamp']) ? -1 : 1;
    }

    private function RecalcDestination($args)
    {
        $this->SendDebug(__FUNCTION__, 'args=' . $args, 0);
        $jargs = json_decode($args, true);

        $mode = $this->ReadPropertyInteger('mode');
        $n_vals = $this->ReadPropertyInteger('count');

        $archivID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];

        if (IPS_SemaphoreEnter($this->SemaphoreID, self::$semaphoreTM) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to lock sempahore ' . $this->SemaphoreID, 0);
            return;
        }

        $start_tm = json_decode($jargs['start_tm'], true);
        if ($start_tm['year'] > 0) {
            $start_ts = mktime($start_tm['hour'], $start_tm['minute'], $start_tm['second'], $start_tm['month'], $start_tm['day'], $start_tm['year']);
        } else {
            $start_ts = 0;
        }

        $end_tm = json_decode($jargs['end_tm'], true);
        if ($end_tm['year'] > 0) {
            $end_ts = mktime($end_tm['hour'], $end_tm['minute'], $end_tm['second'], $end_tm['month'], $end_tm['day'], $end_tm['year']);
        } else {
            $end_ts = time();
        }

        $startS = $start_ts ? date('d.m.Y H:i:s', $start_ts) : '-';
        $endS = $end_ts ? date('d.m.Y H:i:s', $end_ts) : '-';
        $this->SendDebug(__FUNCTION__, 'start=' . $startS . ', end=' . $endS, 0);

        $msg = '';

        $do = true;

        if ($do) {
            $source_varID = $this->ReadPropertyInteger('source_varID');
            if ($source_varID == false) {
                $s = 'no source variable';
                $this->SendDebug(__FUNCTION__, $s, 0);
                $msg .= $s;
                $do = false;
            }
            $this->SendDebug(__FUNCTION__, 'source variable has id ' . $source_varID, 0);
        }
        if ($do) {
            if (AC_GetLoggingStatus($archivID, $source_varID) == false) {
                $s = 'source variable isn\'t logged';
                $this->SendDebug(__FUNCTION__, $s, 0);
                $msg .= $s;
                $do = false;
            }
        }

        if ($do) {
            $ident = 'Destination';
            @$dst_varID = $this->GetIDForIdent($ident);
            if ($dst_varID == false) {
                $s = 'missing destination variable "' . $ident . '"';
                $this->SendDebug(__FUNCTION__, $s, 0);
                $msg .= $s;
                $do = false;
            }
            $this->SendDebug(__FUNCTION__, 'destination variable "' . $ident . '" has id ' . $dst_varID, 0);
        }
        if ($do) {
            $this->SendDebug(__FUNCTION__, 'clear value from destination variable "' . $ident . '"', 0);
            $this->SetValue($ident, 0);

            $this->SendDebug(__FUNCTION__, 'delete all archive values from destination variable "' . $ident . '"', 0);
            $old_num = AC_DeleteVariableData($archivID, $dst_varID, 0, time());
            $msg .= 'deleted all (' . $old_num . ') from destination variable "' . $ident . '"' . PHP_EOL;

            $src_vals = [];
            for ($start = $start_ts; $start < $end_ts; $start = $end + 1) {
                $end = $start + (24 * 60 * 60 * 30) - 1;

                $vals = AC_GetLoggedValues($archivID, $source_varID, $start, $end, 0);
                foreach ($vals as $val) {
                    $src_vals[] = [
                        'TimeStamp' => $val['TimeStamp'],
                        'Value'     => $val['Value'],
                    ];
                }
                $this->SendDebug(__FUNCTION__, 'start=' . date('d.m.Y H:i:s', $start) . ', end=' . date('d.m.Y H:i:s', $end) . ', count=' . count($vals), 0);
            }

            $src_num = count($src_vals);
            usort($src_vals, [__CLASS__, 'cmp_ac_vals']);
            $this->SendDebug(__FUNCTION__, $src_num . ' log-entries', 0);

            switch ($mode) {
                case self::$MODE_UNMODIFIED:
                    break;
                case self::$MODE_AVERAGE:
                    break;
                case self::$MODE_MEDIAN:
                    break;
                case self::$MODE_SIMPLE_MOVING_AVERAGE:
                    $dst_vals = [];
                    for ($i = $n_vals; $i < $src_num; $i++) {
                        $vals = [];
                        for ($j = $i - $n_vals; $j <= $i; $j++) {
                            $vals[] = $src_vals[$j]['Value'];
                        }
                        $n = count($vals);
                        if ($n) {
                            $v = 0.0;
                            for ($k = 0; $k < $n; $k++) {
                                $v += (float) $vals[$k];
                            }
                            $value = round($v / $k);
                            if ($value == -0.0) {
                                $value = 0.0;
                            }
                            $dst_vals[] = [
                                'TimeStamp' => $src_vals[$i]['TimeStamp'],
                                'Value'     => $value,
                            ];
                        }
                    }
                    $vals = [];
                    for ($j = $i - $n_vals; $j < $i; $j++) {
                        $vals[] = $src_vals[$j]['Value'];
                    }
                    $vals[] = GetValueFloat($source_varID);
                    $n = count($vals);
                    if ($n) {
                        $v = 0.0;
                        for ($k = 0; $k < $n; $k++) {
                            $v += (float) $vals[$k];
                        }
                        $end_value = round($v / $k);
                        if ($end_value == -0.0) {
                            $end_value = 0.0;
                        }
                    }
                    break;
                case self::$MODE_WEIGHTED_MOVING_AVERAGE:
                    $dst_vals = [];
                    for ($i = $n_vals; $i < $src_num; $i++) {
                        $vals = [];
                        for ($j = $i - $n_vals; $j <= $i; $j++) {
                            $vals[] = $src_vals[$j]['Value'];
                        }
                        $n = count($vals);
                        if ($n) {
                            $v = 0.0;
                            $f = 0;
                            for ($k = 0; $k < $n; $k++) {
                                $v += (float) $vals[$k] * ($k + 1);
                                $f += ($k + 1);
                            }
                            $value = round($v / $f);
                            if ($value == -0.0) {
                                $value = 0.0;
                            }
                            $dst_vals[] = [
                                'TimeStamp' => $src_vals[$i]['TimeStamp'],
                                'Value'     => $value,
                            ];
                        }
                    }
                    $vals = [];
                    for ($j = $i - $n_vals; $j < $i; $j++) {
                        $vals[] = $src_vals[$j]['Value'];
                    }
                    $vals[] = GetValueFloat($source_varID);
                    $n = count($vals);
                    if ($n) {
                        $v = 0.0;
                        $f = 0;
                        for ($k = 0; $k < $n; $k++) {
                            $v += (float) $vals[$k] * ($k + 1);
                            $f += ($k + 1);
                        }
                        $end_value = round($v / $f);
                        if ($end_value == -0.0) {
                            $end_value = 0.0;
                        }
                    }
                    break;
                default:
                    $do = false;
                    break;
            }

            $dst_num = count($dst_vals);
            $this->SendDebug(__FUNCTION__, 'add ' . $dst_num . ' values to archive', 0);
            if (AC_AddLoggedValues($archivID, $dst_varID, $dst_vals) == false) {
                $s = 'add ' . $dst_num . ' values to destination variable "' . $ident . '" failed';
                $this->SendDebug(__FUNCTION__, $s, 0);
                $msg .= $s;
                $do = false;
            }
        }

        if ($do) {
            $msg .= 'added ' . $dst_num . ' values to destination variable "' . $ident . '"' . PHP_EOL;

            $this->SendDebug(__FUNCTION__, 're-aggregate variable', 0);
            if (AC_ReAggregateVariable($archivID, $dst_varID) == false) {
                $s = 're-aggregate destination variable "' . $ident . '" failed';
                $this->SendDebug(__FUNCTION__, $s, 0);
                $msg .= $s;
                $do = false;
            }
        }

        if ($do) {
            $this->SendDebug(__FUNCTION__, 'set "' . $ident . '" to ' . $end_value, 0);
            $this->SetValue($ident, $end_value);

            $msg .= 'destination variable "' . $ident . '" re-aggregated' . PHP_EOL;
        }

        IPS_SemaphoreLeave($this->SemaphoreID);

        $this->PopupMessage($msg);
    }

    private function UpdateDataByTimer()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $source_varID = $this->ReadPropertyInteger('source_varID');
        $newValue = GetValueFloat($source_varID);
        $this->SendDebug(__FUNCTION__, 'newValue=' . $newValue, 0);

        $this->UpdateData($newValue);

        $this->SendDebug(__FUNCTION__, $this->PrintTimer('UpdateData'), 0);
    }

    private function UpdateDataByEvent($newValue, $oldValue, $changed)
    {
        $this->SendDebug(__FUNCTION__, 'newValue=' . $newValue, 0);
        $this->UpdateData($newValue);
    }

    private function UpdateData($newValue)
    {
        $mode = $this->ReadPropertyInteger('mode');
        $n_vals = $this->ReadPropertyInteger('count');
        $source_varID = $this->ReadPropertyInteger('source_varID');

        $archivID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];

        if (IPS_SemaphoreEnter($this->SemaphoreID, self::$semaphoreTM) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to lock sempahore ' . $this->SemaphoreID, 0);
            return;
        }

        $start = time() - (60 * 60);
        $results = AC_GetLoggedValues($archivID, $source_varID, $start, 0, $n_vals);
        usort($results, [__CLASS__, 'cmp_ac_vals']);
        $vals = [];
        foreach ($results as $result) {
            $vals[] = $result['Value'];
        }
        $n = count($vals);

        switch ($mode) {
            case self::$MODE_UNMODIFIED:
                $this->SendDebug(__FUNCTION__, 'set "Destination" to ' . $newValue, 0);
                $this->SetValue('Destination', $newValue);
                break;
            case self::$MODE_AVERAGE:
                break;
            case self::$MODE_MEDIAN:
                break;
            case self::$MODE_SIMPLE_MOVING_AVERAGE:
                if ($n) {
                    $v = 0.0;
                    if ($n >= $n_vals) {
                        $n = $n_vals - 1;
                    }
                    for ($k = 0; $k < $n; $k++) {
                        $v += (float) $vals[$k];
                    }
                    $v += (float) $newValue;
                    $value = round($v / ($k + 1));
                    if ($value == -0.0) {
                        $value = 0.0;
                    }
                    $this->SendDebug(__FUNCTION__, 'set "Destination" to ' . $value, 0);
                    $this->SetValue('Destination', $value);
                } else {
                    $this->SendDebug(__FUNCTION__, 'no log-entries', 0);
                }
                break;
            case self::$MODE_WEIGHTED_MOVING_AVERAGE:
                if ($n) {
                    $v = 0.0;
                    $f = 0;
                    if ($n >= $n_vals) {
                        $n = $n_vals - 1;
                    }
                    for ($k = 0; $k < $n; $k++) {
                        $v += (float) $vals[$k] * ($k + 1);
                        $f += ($k + 1);
                    }
                    $v += (float) $newValue * ($k + 1);
                    $f += ($k + 1);
                    $value = round($v / $f);
                    if ($value == -0.0) {
                        $value = 0.0;
                    }
                    $this->SendDebug(__FUNCTION__, 'set "Destination" to ' . $value, 0);
                    $this->SetValue('Destination', $value);
                } else {
                    $this->SendDebug(__FUNCTION__, 'no log-entries', 0);
                }
                break;
            default:
                break;
        }

        IPS_SemaphoreLeave($this->SemaphoreID);
    }
}
