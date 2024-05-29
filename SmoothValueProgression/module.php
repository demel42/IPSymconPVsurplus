<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class SmoothValueProgression extends IPSModule
{
    use SmoothValueProgression\StubsCommonLib;
    use SmoothValueProgressionLocalLib;

    private static $semaphoreTM = 5 * 1000;

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

        $this->RegisterTimer('UpdateDestination', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateDestination", "");');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        parent::MessageSink($timestamp, $senderID, $message, $data);

        if ($message == IPS_KERNELMESSAGE && $data[0] == KR_READY) {
            $this->SetUpdateInterval();
        }

        if (IPS_GetKernelRunlevel() == KR_READY && $message == VM_UPDATE && $data[1] == true /* changed */) {
            $this->SendDebug(__FUNCTION__, 'timestamp=' . $timestamp . ', senderID=' . $senderID . ', message=' . $message . ', data=' . print_r($data, true), 0);
            // $this->UpdateVariable($data[0], $data[2], $data[1]);
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
            $this->MaintainTimer('UpdateDestination', 0);
            $this->MaintainStatus(self::$IS_INVALIDPREREQUISITES);
            return;
        }

        if ($this->CheckUpdate() != false) {
            $this->MaintainTimer('UpdateDestination', 0);
            $this->MaintainStatus(self::$IS_UPDATEUNCOMPLETED);
            return;
        }

        if ($this->CheckConfiguration() != false) {
            $this->MaintainTimer('UpdateDestination', 0);
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

            $this->MaintainVariable('Destination', 'smoothed value', $variableType, $variableProfile, $vpos++, true);

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
            $this->MaintainTimer('UpdateDestination', 0);
            $this->MaintainStatus(IS_INACTIVE);
            return;
        }

        $source_varID = $this->ReadPropertyInteger('source_varID');
        if (IPS_VariableExists($source_varID)) {
            $this->RegisterMessage($source_varID, VM_UPDATE);
        }

        $this->MaintainStatus(IS_ACTIVE);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->SetUpdateInterval();
        }
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
            'requiredLogging'    => 3, /* Logging als Standard */
            'validVariableTypes' => [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT],
            'width'              => '500px',
            'caption'            => 'Source variable',
        ];

        $mode = $this->ReadPropertyInteger('mode');
        switch ($mode) {
            case self::$MODE_AVERAGE:
            case self::$MODE_MEDIAN:
                $visible_count = false;
                $visible_interval = true;
                break;
            case self::$MODE_SIMPLE_MOVING_AVERAGE:
            case self::$MODE_WEIGHTED_MOVING_AVERAGE:
            case self::$MODE_EXPONENTIAL_MOVING_AVERAGE:
                $visible_count = true;
                $visible_interval = false;
                break;
            case self::$MODE_UNMODIFIED:
            default:
                $visible_count = false;
                $visible_interval = false;
                break;
        }

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
                    'visible' => $visible_interval,
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'count',
                    'minimum' => 1,
                    'caption' => 'Number of values to be used',
                    'visible' => $visible_count,
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

        $formActions[] = [
            'type'      => 'ExpansionPanel',
            'caption'   => 'Expert area',
            'expanded'  => false,
            'items'     => [
                $this->GetInstallVarProfilesFormItem(),
            ],
        ];

        $formActions[] = [
            'type'      => 'ExpansionPanel',
            'caption'   => 'Test area',
            'expanded'  => false,
            'items'     => [
                [
                    'type'    => 'TestCenter',
                ],
            ]
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
        $this->MaintainTimer('UpdateDestination', $sec * 1000);
    }

    private function UpdateDestination()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, $this->PrintTimer('UpdateDestination'), 0);
    }

    private function LocalRequestAction($ident, $value)
    {
        $r = true;
        switch ($ident) {
            case 'UpdateDestination':
                break;
            case 'UpdateFormField4Mode':
                switch ($value) {
                    case self::$MODE_AVERAGE:
                    case self::$MODE_MEDIAN:
                        $this->UpdateFormField('count', 'visible', false);
                        $this->UpdateFormField('interval', 'visible', true);
                        break;
                    case self::$MODE_SIMPLE_MOVING_AVERAGE:
                    case self::$MODE_WEIGHTED_MOVING_AVERAGE:
                    case self::$MODE_EXPONENTIAL_MOVING_AVERAGE:
                        $this->UpdateFormField('count', 'visible', true);
                        $this->UpdateFormField('interval', 'visible', false);
                        break;
                    case self::$MODE_UNMODIFIED:
                    default:
                        $this->UpdateFormField('count', 'visible', false);
                        $this->UpdateFormField('interval', 'visible', false);
                        break;
                }
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

    private function RebuildDestination($args)
    {
        $this->SendDebug(__FUNCTION__, 'args=' . $args, 0);
        $jargs = json_decode($args, true);

        $destination = $jargs['destination'];

        $now = time();

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
            $end_ts = $now;
        }

        $startS = $start_ts ? date('d.m.Y H:i:s', $start_ts) : '-';
        $endS = $end_ts ? date('d.m.Y H:i:s', $end_ts) : '-';
        $this->SendDebug(__FUNCTION__, 'destination=' . $destination . ', start=' . $startS . ', end=' . $endS, 0);

        $archivID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];

        $msg = '';

        $do = true;

        if ($do) {
            $varID_src = $this->ReadPropertyInteger('source_varID');
            if ($varID_src == false) {
                $s = 'no source variable';
                $this->SendDebug(__FUNCTION__, $s, 0);
                $msg .= $s;
                $do = false;
            }
            $this->SendDebug(__FUNCTION__, 'source variable has id ' . $varID_src, 0);
        }
        if ($do) {
            if (AC_GetLoggingStatus($archivID, $varID_src) == false) {
                $s = 'source variable isn\'t logged';
                $this->SendDebug(__FUNCTION__, $s, 0);
                $msg .= $s;
                $do = false;
            }
        }

        if ($do) {
            $ident_dst = self::$ident_var_pfx . $destination;
            @$varID_dst = $this->GetIDForIdent($ident_dst);
            if ($varID_dst == false) {
                $s = 'missing destination variable "' . $ident_dst . '"';
                $this->SendDebug(__FUNCTION__, $s, 0);
                $msg .= $s;
                $do = false;
            }
            $this->SendDebug(__FUNCTION__, 'destination variable "' . $ident_dst . '" has id ' . $varID_dst, 0);
        }
        if ($do) {
            $this->SendDebug(__FUNCTION__, 'clear value from destination variable "' . $ident_dst . '"', 0);
            $this->SetValue($ident_dst, 0);

            $this->SendDebug(__FUNCTION__, 'delete all archive values from destination variable "' . $ident_dst . '"', 0);
            $old_num = AC_DeleteVariableData($archivID, $varID_dst, 0, time());
            $msg .= 'deleted all (' . $old_num . ') from destination variable "' . $ident_dst . '"' . PHP_EOL;

            $dst_vals = [];
            for ($start = $start_ts; $start < $end_ts; $start = $end + 1) {
                $end = $start + (24 * 60 * 60 * 30) - 1;

                $src_vals = AC_GetLoggedValues($archivID, $varID_src, $start, $end, 0);
                foreach ($src_vals as $val) {
                    $dst_vals[] = [
                        'TimeStamp' => $val['TimeStamp'],
                        'Value'     => $val['Value'],
                    ];
                }
                $this->SendDebug(__FUNCTION__, 'start=' . date('d.m.Y H:i:s', $start) . ', end=' . date('d.m.Y H:i:s', $end) . ', count=' . count($src_vals), 0);
            }
            $dst_num = count($dst_vals);
            if ($dst_num > 0) {
                usort($dst_vals, [__CLASS__, 'cmp_ac_vals']);
                $start_value = $dst_vals[0]['Value'];
                $start_updated = $dst_vals[0]['TimeStamp'];
                for ($i = 0; $i < $dst_num; $i++) {
                    $dst_vals[$i]['Value'] -= $start_value;
                }
                if ($end_ts == $now) {
                    $end_value = GetValue($varID_src);
                } else {
                    $v = array_pop($dst_vals);
                    $end_value = $v['Value'];
                }
            } else {
                $start_value = 0;
                $start_updated = 0;
                $end_value = GetValue($varID_src);
            }
            $this->SendDebug(__FUNCTION__, 'add ' . $dst_num . ' values, start_val=' . $start_value, 0);
            if (AC_AddLoggedValues($archivID, $varID_dst, $dst_vals) == false) {
                $s = 'add ' . $dst_num . ' values to destination variable "' . $ident_dst . '" failed';
                $this->SendDebug(__FUNCTION__, $s, 0);
                $msg .= $s;
                $do = false;
            }
        }

        if ($do) {
            $msg .= 'added ' . $dst_num . ' values to destination variable "' . $ident_dst . '"' . PHP_EOL;

            $this->SendDebug(__FUNCTION__, 're-aggregate variable', 0);
            if (AC_ReAggregateVariable($archivID, $varID_dst) == false) {
                $s = 're-aggregate destination variable "' . $ident_dst . '" failed';
                $this->SendDebug(__FUNCTION__, $s, 0);
                $msg .= $s;
                $do = false;
            }
        }

        if ($do) {
            $this->SendDebug(__FUNCTION__, 'set value from destination variable "' . $ident_dst . '"', 0);
            $this->SetValue($ident_dst, $end_value);

            $msg .= 'destination variable "' . $ident_dst . '" re-aggregated' . PHP_EOL;
        }

        $this->PopupMessage($msg);
    }
}
