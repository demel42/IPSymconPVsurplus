<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class PVsurplusDetermination extends IPSModule
{
    use PVsurplus\StubsCommonLib;
    use PVsurplusLocalLib;

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
        $this->RegisterPropertyBoolean('source_invert', false);
        $this->RegisterPropertyInteger('source_unit', 1);

        $this->RegisterPropertyInteger('method', self::$METHOD_WEIGHTED_MOVING_AVERAGE);
        $this->RegisterPropertyInteger('trigger', self::$TRIGGER_UPDATE);
        $this->RegisterPropertyInteger('quantity', 10);
        $this->RegisterPropertyInteger('interval', 60);
        $this->RegisterPropertyBoolean('log_smoothed', true);

        $this->RegisterPropertyInteger('storage_soc_varID', 0);
        $this->RegisterPropertyInteger('storage_soc_unit', 100);
        $this->RegisterPropertyString('storage_charging_power', json_encode([]));
        $this->RegisterPropertyInteger('storage_discharge_varID', 0);
        $this->RegisterPropertyInteger('storage_discharge_unit', 1);
        $this->RegisterPropertyInteger('hysteresis', 0);
        $this->RegisterPropertyBoolean('log_usable', true);

        $this->RegisterPropertyInteger('storage_charge_varID', 0);
        $this->RegisterPropertyInteger('storage_charge_unit', 1);

        $this->RegisterPropertyString('ev_supported_phases', '1');
        $this->RegisterPropertyInteger('ev_phases_varID', 0);
        $this->RegisterPropertyInteger('ev_current_min', 6);
        $this->RegisterPropertyInteger('ev_current_max', 16);
        $this->RegisterPropertyInteger('ev_actual_power_varID', 0);
        $this->RegisterPropertyInteger('ev_actual_power_unit', 1);

        $this->RegisterAttributeString('UpdateInfo', json_encode([]));
        $this->RegisterAttributeString('ModuleStats', json_encode([]));

        $this->InstallVarProfiles(false);

        $this->RegisterTimer('SmoothValue', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "SmoothValueByTimer", "");');
        $this->RegisterTimer('CalcSurplus', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "CalcSurplus", "");');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        parent::MessageSink($timestamp, $senderID, $message, $data);

        if ($message == IPS_KERNELMESSAGE && $data[0] == KR_READY) {
            $this->SetUpdateInterval();
            $trigger = $this->ReadPropertyInteger('trigger');
            if ($trigger != self::$TRIGGER_CYCLIC) {
                $source_varID = $this->ReadPropertyInteger('source_varID');
                if (IPS_VariableExists($source_varID)) {
                    $this->RegisterMessage($source_varID, VM_UPDATE);
                }
            }
            $varIDs = [
                $this->ReadPropertyInteger('storage_soc_varID'),
                $this->ReadPropertyInteger('storage_discharge_varID'),
            ];
            foreach ($varIDs as $varID) {
                if (IPS_VariableExists($varID)) {
                    $this->RegisterMessage($varID, VM_UPDATE);
                }
            }
        }

        if (IPS_GetKernelRunlevel() == KR_READY && $message == VM_UPDATE && $data[1] == true /* changed */) {
            $this->SendDebug(__FUNCTION__, 'timestamp=' . $timestamp . ', senderID=' . $senderID . ', message=' . $message . ', data=' . print_r($data, true), 0);
            $source_varID = $this->ReadPropertyInteger('source_varID');
            $storage_soc_varID = $this->ReadPropertyInteger('storage_soc_varID');
            $storage_discharge_varID = $this->ReadPropertyInteger('storage_discharge_varID');
            $ev_phases_varID = $this->ReadPropertyInteger('ev_phases_varID');
            switch ($senderID) {
                case $source_varID:
                    $newValue = $data[0];
                    $changed = (bool) $data[0];
                    $oldValue = $data[2];
                    $trigger = $this->ReadPropertyInteger('trigger');
                    if ($trigger == self::$TRIGGER_UPDATE || ($trigger == self::$TRIGGER_CHANGE && §changed)) {
                        $this->SmoothValueByEvent($data[0]);
                    }
                    break;
                case $storage_soc_varID:
                case $storage_discharge_varID:
                case $ev_phases_varID:
                    $this->DelayCalcSurplus();
                    break;
                default:
                    break;
            }
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

        $propertyNames = [
            'source_varID',
            'storage_soc_varID',
            'storage_discharge_varID',
            'ev_phases_varID',
            'ev_actual_power_varID',
        ];
        $this->MaintainReferences($propertyNames);

        $this->UnregisterMessages([VM_UPDATE]);

        $this->MaintainTimer('CalcSurplus', 0);

        if ($this->CheckPrerequisites() != false) {
            $this->MaintainTimer('SmoothValue', 0);
            $this->MaintainStatus(self::$IS_INVALIDPREREQUISITES);
            return;
        }

        if ($this->CheckUpdate() != false) {
            $this->MaintainTimer('SmoothValue', 0);
            $this->MaintainStatus(self::$IS_UPDATEUNCOMPLETED);
            return;
        }

        if ($this->CheckConfiguration() != false) {
            $this->MaintainTimer('SmoothValue', 0);
            $this->MaintainStatus(self::$IS_INVALIDCONFIG);
            return;
        }

        $vpos = 1;

        $source_varID = $this->ReadPropertyInteger('source_varID');
        $source_valid = IPS_VariableExists($source_varID);

        $storage_soc_varID = $this->ReadPropertyInteger('storage_soc_varID');
        $storage_discharge_varID = $this->ReadPropertyInteger('storage_discharge_varID');
        $storage_soc_valid = IPS_VariableExists($storage_soc_varID);
        $storage_discharge_valid = IPS_VariableExists($storage_discharge_varID);

        if ($source_valid) {
            $var = IPS_GetVariable($source_varID);
            $variableType = $var['VariableType'];
            $variableProfile = $var['VariableProfile'];
            $variableCustomProfile = $var['VariableCustomProfile'];

            $varID = @$this->GetIDForIdent('SmoothedSurplusPower');
            $this->MaintainVariable('SmoothedSurplusPower', $this->Translate('Smoothed surplus power'), $variableType, $variableProfile, $vpos++, true);
            if ($varID == false) {
                $varID = $this->GetIDForIdent('SmoothedSurplusPower');
                IPS_SetVariableCustomProfile($varID, $variableCustomProfile);
            }

            $log_smoothed = $this->ReadPropertyBoolean('log_smoothed');
            if ($log_smoothed) {
                $this->SetVariableLogging('SmoothedSurplusPower', 0 /* Standard */);
            } else {
                $this->UnsetVariableLogging('SmoothedSurplusPower');
            }
        } else {
            $this->UnregisterVariable('SmoothedSurplusPower');
        }

        if ($source_valid && $storage_soc_valid && $storage_discharge_valid) {
            $varID = @$this->GetIDForIdent('UsableSurplusPower');
            $this->MaintainVariable('UsableSurplusPower', $this->Translate('Usable surplus power'), $variableType, $variableProfile, $vpos++, true);
            if ($varID == false) {
                $varID = $this->GetIDForIdent('UsableSurplusPower');
                IPS_SetVariableCustomProfile($varID, $variableCustomProfile);
            }

            $log_usable = $this->ReadPropertyBoolean('log_usable');
            if ($log_usable) {
                $this->SetVariableLogging('UsableSurplusPower', 0 /* Standard */);
            } else {
                $this->UnsetVariableLogging('UsableSurplusPower');
            }

            $this->MaintainVariable('ChargePriority', $this->Translate('Priority of charging the storage'), VARIABLETYPE_INTEGER, 'PVsurplus.ChargePriority', $vpos++, true);
            $this->MaintainAction('ChargePriority', true);
        } else {
            $this->UnregisterVariable('UsableSurplusPower');
            $this->UnregisterVariable('ChargePriority');
        }

        $this->MaintainVariable('SurplusUse', $this->Translate('Use of the PV surplus'), VARIABLETYPE_INTEGER, 'PVsurplus.SurplusUse', $vpos++, true);
        $this->MaintainAction('SurplusUse', true);

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->MaintainTimer('SmoothValue', 0);
            $this->MaintainStatus(IS_INACTIVE);
            return;
        }

        $this->MaintainStatus(IS_ACTIVE);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->SetUpdateInterval();
            $trigger = $this->ReadPropertyInteger('trigger');
            if ($trigger != self::$TRIGGER_CYCLIC) {
                $source_varID = $this->ReadPropertyInteger('source_varID');
                if (IPS_VariableExists($source_varID)) {
                    $this->RegisterMessage($source_varID, VM_UPDATE);
                }
            }
            $varIDs = [
                $this->ReadPropertyInteger('storage_soc_varID'),
                $this->ReadPropertyInteger('storage_discharge_varID'),
                $this->ReadPropertyInteger('ev_phases_varID'),
            ];
            foreach ($varIDs as $varID) {
                if (IPS_VariableExists($varID)) {
                    $this->RegisterMessage($varID, VM_UPDATE);
                }
            }
        }
    }

    private function GetFormElements()
    {
        $formElements = $this->GetCommonFormElements('Determination of the usable PV surplus');

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            return $formElements;
        }

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'module_disable',
            'caption' => 'Disable instance',
        ];

        $formElements[] = [
            'type'    => 'RowLayout',
            'items'   => [
                [
                    'name'               => 'source_varID',
                    'type'               => 'SelectVariable',
                    'requiredLogging'    => 3, /* Standard-Logging */
                    'validVariableTypes' => [VARIABLETYPE_FLOAT],
                    'width'              => '600px',
                    'caption'            => 'Variable of the net grid power',
                ],
                [
                    'type'     => 'Select',
                    'options'  => [
                        [
                            'caption' => 'W',
                            'value'   => 1,
                        ],
                        [
                            'caption' => 'kW',
                            'value'   => 1000, /* multiply by 1000 */
                        ],
                    ],
                    'name'     => 'source_unit',
                    'caption'  => 'Unit',
                ],
                [
                    'type'     => 'Select',
                    'options'  => [
                        [
                            'caption' => 'Surplus ist positive',
                            'value'   => false,
                        ],
                        [
                            'caption' => 'Surplus ist negative',
                            'value'   => true,
                        ],
                    ],
                    'name'     => 'source_invert',
                    'caption'  => 'Invert source',
                ],
            ],
        ];

        $method = $this->ReadPropertyInteger('method');
        $use_quantity = $method != self::$METHOD_UNMODIFIED;

        $formElements[] = [
            'type'    => 'RowLayout',
            'items'   => [
                [
                    'type'     => 'Select',
                    'options'  => $this->MethodAsOptions(),
                    'name'     => 'method',
                    'caption'  => 'Smoothing method',
                    'onChange' => 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateFormField4Method", $method);',
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'quantity',
                    'minimum' => 1,
                    'caption' => 'Number of pre-values to be used for each value',
                    'visible' => $use_quantity,
                ],
            ],
        ];

        $trigger = $this->ReadPropertyInteger('trigger');
        $use_interval = $trigger == self::$TRIGGER_CYCLIC;

        $formElements[] = [
            'type'    => 'RowLayout',
            'items'   => [
                [
                    'type'     => 'Select',
                    'options'  => $this->TriggerAsOptions(),
                    'name'     => 'trigger',
                    'caption'  => 'Trigger',
                    'onChange' => 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateFormField4Trigger", $trigger);',
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'interval',
                    'suffix'  => 'Seconds',
                    'minimum' => 0,
                    'caption' => 'Interval between calculations',
                    'visible' => $use_interval,
                ],
            ],
        ];

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'log_smoothed',
            'caption' => 'Activate logging of smoothed surplus',
        ];

        $formElements[] = [
            'type'      => 'ExpansionPanel',
            'caption'   => 'PV storage',
            'expanded'  => false,
            'items'     => [
                [
                    'type'    => 'RowLayout',
                    'items'   => [
                        [
                            'name'               => 'storage_soc_varID',
                            'type'               => 'SelectVariable',
                            'validVariableTypes' => [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT],
                            'width'              => '600px',
                            'caption'            => 'Variable of PV storage SoC',
                        ],
                        [
                            'type'     => 'Select',
                            'options'  => [
                                [
                                    'caption' => '% (value range from 0..1)',
                                    'value'   => 100, /* multiply by 100 */
                                ],
                                [
                                    'caption' => '% (value range from 0..100)',
                                    'value'   => 1,
                                ],
                            ],
                            'name'     => 'storage_soc_unit',
                            'caption'  => 'Unit',
                        ],
                    ],
                ],
                [
                    'name'        => 'storage_charging_power',
                    'type'        => 'List',
                    'rowCount'    => 3,
                    'add'         => true,
                    'delete'      => true,
                    'changeOrder' => true,
                    'columns'     => [
                        [
                            'name'    => 'limit',
                            'add'     => 100,
                            'edit'    => [
                                'type'    => 'NumberSpinner',
                                'digits'  => 0,
                                'minimum' => 0,
                                'maximum' => 100,
                                'suffix'  => '%',
                            ],
                            'width'   => '250px',
                            'caption' => 'Up to SoC of',
                        ],
                        [
                            'name'    => 'normal',
                            'add'     => 0,
                            'edit'    => [
                                'type'    => 'NumberSpinner',
                                'digits'  => 0,
                                'minimum' => 0,
                                'suffix'  => 'W',
                            ],
                            'width'   => '200px',
                            'caption' => 'Normal priority',
                        ],
                        [
                            'name'    => 'high',
                            'add'     => 0,
                            'edit'    => [
                                'type'    => 'NumberSpinner',
                                'digits'  => 0,
                                'minimum' => 0,
                                'suffix'  => 'W',
                            ],
                            'width'   => '200px',
                            'caption' => 'High priority',
                        ],
                        [
                            'name'    => 'low',
                            'add'     => 0,
                            'edit'    => [
                                'type'    => 'NumberSpinner',
                                'digits'  => 0,
                                'minimum' => 0,
                                'suffix'  => 'W',
                            ],
                            'width'   => '200px',
                            'caption' => 'Low priority',
                        ],
                    ],
                    'sort'     => [
                        'column'    => 'limit',
                        'direction' => 'ascending'
                    ],
                    'caption'  => 'Reserved power for storage charging',
                ],
                [
                    'type'    => 'RowLayout',
                    'items'   => [
                        [
                            'type'               => 'SelectVariable',
                            'name'               => 'storage_discharge_varID',
                            'validVariableTypes' => [VARIABLETYPE_FLOAT],
                            'width'              => '600px',
                            'caption'            => 'Variable of current storage discharge',
                        ],
                        [
                            'type'     => 'Select',
                            'options'  => [
                                [
                                    'caption' => 'W',
                                    'value'   => 1,
                                ],
                                [
                                    'caption' => 'kW',
                                    'value'   => 1000, /* multiply by 1000 */
                                ],
                            ],
                            'name'     => 'storage_discharge_unit',
                            'caption'  => 'Unit',
                        ],
                    ],
                ],
            ],
        ];

        $formElements[] = [
            'type'      => 'ExpansionPanel',
            'caption'   => 'Wallbox',
            'expanded'  => false,
            'items'     => [
                [
                    'type'     => 'Select',
                    'options'  => [
                        [
                            'caption' => '1',
                            'value'   => '1',
                        ],
                        [
                            'caption' => '1/2',
                            'value'   => '1/2',
                        ],
                        [
                            'caption' => '1/2/3',
                            'value'   => '1/2/3',
                        ],
                        [
                            'caption' => '1/3',
                            'value'   => '1/3',
                        ],
                    ],
                    'name'     => 'ev_supported_phases',
                    'caption'  => 'Supported number of phases',
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'ev_current_min',
                    'suffix'  => 'W',
                    'minimum' => 0,
                    'caption' => 'Minimal current',
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'ev_current_max',
                    'suffix'  => 'W',
                    'minimum' => 0,
                    'caption' => 'Maximal current',
                ],
                [
                    'type'               => 'SelectVariable',
                    'name'               => 'ev_phases_varID',
                    'validVariableTypes' => [VARIABLETYPE_INTEGER],
                    'width'              => '600px',
                    'caption'            => 'Variable of the current number of phases used',
                ],
                [
                    'type'    => 'RowLayout',
                    'items'   => [
                        [
                            'type'               => 'SelectVariable',
                            'name'               => 'ev_actual_power_varID',
                            'validVariableTypes' => [VARIABLETYPE_FLOAT],
                            'width'              => '600px',
                            'caption'            => 'Variable of actual power',
                        ],
                        [
                            'type'     => 'Select',
                            'options'  => [
                                [
                                    'caption' => 'W',
                                    'value'   => 1,
                                ],
                                [
                                    'caption' => 'kW',
                                    'value'   => 1000, /* multiply by 1000 */
                                ],
                            ],
                            'name'     => 'ev_actual_power_unit',
                            'caption'  => 'Unit',
                        ],
                    ],
                ],
            ],
        ];

        $formElements[] = [
            'type'    => 'NumberSpinner',
            'name'    => 'hysteresis',
            'suffix'  => 'W',
            'minimum' => 0,
            'caption' => 'Hysteresis',
        ];

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'log_usable',
            'caption' => 'Activate logging of usable surplus',
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
        $trigger = $this->ReadPropertyInteger('trigger');
        if ($trigger == self::$TRIGGER_CYCLIC) {
            $sec = $this->ReadPropertyInteger('interval');

            @$varID = $this->GetIDForIdent('SmoothedSurplusPower');
            if ($varID != false) {
                $var = IPS_GetVariable($varID);
                $age = time() - $var['VariableUpdated'];
                $dif = $sec - $age;
                if ($dif > 0) {
                    $msec = $dif * 1000;
                } elseif ($dif < 0) {
                    $msec = 1;
                } else {
                    $msec = $sec * 1000;
                }
            }
        } else {
            $msec = 0;
        }
        $this->MaintainTimer('SmoothValue', $msec);
    }

    private function DelayCalcSurplus()
    {
        $timer = $this->GetTimerByName('CalcSurplus');
        if ($timer == false || $timer['Interval'] == 0) {
            $this->MaintainTimer('CalcSurplus', 100);
        }
    }

    private function LocalRequestAction($ident, $value)
    {
        $r = true;
        switch ($ident) {
            case 'SmoothValueByTimer':
                $this->SmoothValueByTimer();
                break;
            case 'UpdateFormField4Method':
                $use_quantity = $value != self::$METHOD_UNMODIFIED;
                $this->UpdateFormField('quantity', 'visible', $use_quantity);
                break;
            case 'CalcSurplus':
                $this->CalcSurplus();
                break;
            case 'UpdateFormField4Trigger':
                $use_interval = $value == self::$TRIGGER_CYCLIC;
                $this->UpdateFormField('interval', 'visible', $use_interval);
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
            case 'SurplusUse':
                $r = $this->SetSurplusUse((int) $value);
                break;
            default:
                $this->SendDebug(__FUNCTION__, 'invalid ident ' . $ident, 0);
                break;
        }
        if ($r) {
            $this->SetValue($ident, $value);
        }
    }

    public function SurplusUse(int $surplusUse)
    {
        $this->SendDebug(__FUNCTION__, 'surplusUse=' . $surplusUse, 0);

        $this->CalcSurplus($surplusUse);

        return true;
    }

    private function cmp_ac_vals($a, $b)
    {
        return ($a['TimeStamp'] < $b['TimeStamp']) ? -1 : 1;
    }

    private function RecalcDestination($args)
    {
        $this->SendDebug(__FUNCTION__, 'args=' . $args, 0);
        $jargs = json_decode($args, true);

        $source_varID = $this->ReadPropertyInteger('source_varID');
        $source_invert = $this->ReadPropertyBoolean('source_invert');
        $source_unit = $this->ReadPropertyInteger('source_unit');
        $source_factor = $source_invert ? (-1 * $source_unit) : $source_unit;
        $method = $this->ReadPropertyInteger('method');
        $quantity = $this->ReadPropertyInteger('quantity');
        $trigger = $this->ReadPropertyInteger('trigger');
        $interval = $this->ReadPropertyInteger('interval');

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
            @$varID = $this->GetIDForIdent('SmoothedSurplusPower');
            if ($varID == false) {
                $s = 'missing destination variable "' . 'SmoothedSurplusPower' . '"';
                $this->SendDebug(__FUNCTION__, $s, 0);
                $msg .= $s;
                $do = false;
            }
            $this->SendDebug(__FUNCTION__, 'destination variable "' . 'SmoothedSurplusPower' . '" has id ' . $varID, 0);
        }
        if ($do) {
            $this->SendDebug(__FUNCTION__, 'clear value from destination variable "' . 'SmoothedSurplusPower' . '"', 0);
            $this->SetValue('SmoothedSurplusPower', 0);

            $this->SendDebug(__FUNCTION__, 'delete all archive values from destination variable "' . 'SmoothedSurplusPower' . '"', 0);
            $old_num = AC_DeleteVariableData($archivID, $varID, 0, time());
            $msg .= 'deleted all (' . $old_num . ') from destination variable "' . 'SmoothedSurplusPower' . '"' . PHP_EOL;

            $src_vals = [];
            for ($start = $start_ts; $start < $end_ts; $start = $end + 1) {
                $end = $start + (24 * 60 * 60 * 30) - 1;

                $vals = AC_GetLoggedValues($archivID, $source_varID, $start, $end, 0);
                foreach ($vals as $val) {
                    $src_vals[] = [
                        'TimeStamp' => $val['TimeStamp'],
                        'Value'     => (float) $val['Value'] * $source_factor * $source_factor,
                    ];
                }
                $this->SendDebug(__FUNCTION__, 'start=' . date('d.m.Y H:i:s', $start) . ', end=' . date('d.m.Y H:i:s', $end) . ', count=' . count($vals), 0);
            }

            $src_num = count($src_vals);
            usort($src_vals, [__CLASS__, 'cmp_ac_vals']);
            $src_steps = [];
            if ($trigger != self::$TRIGGER_CYCLIC) {
                for ($i = $quantity; $i < $src_num; $i++) {
                    $src_steps[] = $i;
                }
                $this->SendDebug(__FUNCTION__, $src_num . ' log-entries', 0);
            } else {
                for ($ts = 0, $i = $quantity; $i < $src_num; $i++) {
                    if ($ts && $src_vals[$i]['TimeStamp'] < $ts) {
                        continue;
                    }
                    $src_steps[] = $i;
                    $ts = $src_vals[$i]['TimeStamp'] + $interval;
                }
                $this->SendDebug(__FUNCTION__, $src_num . ' log-entries, ' . count($src_steps) . ' to be used', 0);
            }

            switch ($method) {
                case self::$METHOD_UNMODIFIED:
                    $dst_vals = [];
                    foreach ($src_steps as $src_step) {
                        $dst_vals[] = [
                            'TimeStamp' => $src_vals[$src_step]['TimeStamp'],
                            'Value'     => $value,
                        ];
                    }
                    $end_value = GetValueFloat($source_varID) * $source_factor;
                    break;
                case self::$METHOD_SIMPLE_MOVING_AVERAGE:
                    $dst_vals = [];
                    foreach ($src_steps as $src_step) {
                        $vals = [];
                        for ($j = $src_step - $quantity; $j <= $src_step; $j++) {
                            $vals[] = $src_vals[$j]['Value'];
                        }
                        $n = count($vals);
                        if ($n) {
                            $v = 0.0;
                            for ($k = 0; $k < $n; $k++) {
                                $v += (float) $vals[$k];
                            }
                            $value = round($v / $n);
                            if ($value == -0.0) {
                                $value = 0.0;
                            }
                            $dst_vals[] = [
                                'TimeStamp' => $src_vals[$src_step]['TimeStamp'],
                                'Value'     => $value,
                            ];
                        }
                    }
                    $vals = [];
                    for ($j = $src_step - $quantity; $j < $src_step; $j++) {
                        $vals[] = $src_vals[$j]['Value'];
                    }
                    $vals[] = GetValueFloat($source_varID) * $source_factor;
                    $n = count($vals);
                    if ($n) {
                        $v = 0.0;
                        for ($k = 0; $k < $n; $k++) {
                            $v += (float) $vals[$k];
                        }
                        $end_value = round($v / $n);
                        if ($end_value == -0.0) {
                            $end_value = 0.0;
                        }
                    }
                    break;
                case self::$METHOD_WEIGHTED_MOVING_AVERAGE:
                    $dst_vals = [];
                    foreach ($src_steps as $src_step) {
                        $vals = [];
                        for ($j = $src_step - $quantity; $j <= $src_step; $j++) {
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
                                'TimeStamp' => $src_vals[$src_step]['TimeStamp'],
                                'Value'     => $value,
                            ];
                        }
                    }
                    $vals = [];
                    for ($j = $src_step - $quantity; $j < $src_step; $j++) {
                        $vals[] = $src_vals[$j]['Value'];
                    }
                    $vals[] = GetValueFloat($source_varID) * $source_factor;
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
            if (AC_AddLoggedValues($archivID, $varID, $dst_vals) == false) {
                $s = 'add ' . $dst_num . ' values to destination variable "' . 'SmoothedSurplusPower' . '" failed';
                $this->SendDebug(__FUNCTION__, $s, 0);
                $msg .= $s;
                $do = false;
            }
        }

        if ($do) {
            $msg .= 'added ' . $dst_num . ' values to destination variable "' . 'SmoothedSurplusPower' . '"' . PHP_EOL;

            $this->SendDebug(__FUNCTION__, 're-aggregate variable', 0);
            if (AC_ReAggregateVariable($archivID, $varID) == false) {
                $s = 're-aggregate destination variable "' . 'SmoothedSurplusPower' . '" failed';
                $this->SendDebug(__FUNCTION__, $s, 0);
                $msg .= $s;
                $do = false;
            }
        }

        if ($do) {
            $this->SendDebug(__FUNCTION__, 'set "' . 'SmoothedSurplusPower' . '" to ' . $end_value, 0);
            $this->SetValue('SmoothedSurplusPower', $end_value);

            $msg .= 'destination variable "' . 'SmoothedSurplusPower' . '" re-aggregated' . PHP_EOL;
        }

        IPS_SemaphoreLeave($this->SemaphoreID);

        $this->DelayCalcSurplus();
        $this->SetUpdateInterval();

        $this->PopupMessage($msg);
    }

    private function SmoothValueByTimer()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $source_varID = $this->ReadPropertyInteger('source_varID');
        $newValue = GetValueFloat($source_varID);
        $this->SendDebug(__FUNCTION__, 'newValue=' . $newValue, 0);

        $this->SmoothValue($newValue);
        $this->SetUpdateInterval();
    }

    private function SmoothValueByEvent($newValue)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, 'newValue=' . $newValue, 0);
        $this->SmoothValue($newValue);
    }

    private function SmoothValue($newValue)
    {
        $method = $this->ReadPropertyInteger('method');
        $quantity = $this->ReadPropertyInteger('quantity');
        $source_varID = $this->ReadPropertyInteger('source_varID');
        $source_invert = $this->ReadPropertyBoolean('source_invert');
        $source_unit = $this->ReadPropertyInteger('source_unit');
        $source_factor = $source_invert ? (-1 * $source_unit) : $source_unit;

        $archivID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];

        if (IPS_SemaphoreEnter($this->SemaphoreID, self::$semaphoreTM) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to lock sempahore ' . $this->SemaphoreID, 0);
            return;
        }

        $newValue *= $source_factor;

        $start = time() - (60 * 60);
        $results = AC_GetLoggedValues($archivID, $source_varID, $start, 0, $quantity);
        usort($results, [__CLASS__, 'cmp_ac_vals']);
        $vals = [];
        foreach ($results as $result) {
            $vals[] = (float) $result['Value'] * $source_factor;
        }
        $n = count($vals);

        switch ($method) {
            case self::$METHOD_UNMODIFIED:
                $value = $newValue;
                    $this->SendDebug(__FUNCTION__, 'set "' . 'SmoothedSurplusPower' . '" to ' . $value, 0);
                    $this->SetValue('SmoothedSurplusPower', $value);
                break;
            case self::$METHOD_SIMPLE_MOVING_AVERAGE:
                if ($n) {
                    $v = 0.0;
                    if ($n >= $quantity) {
                        $n = $quantity - 1;
                    }
                    for ($k = 0; $k < $n; $k++) {
                        $v += (float) $vals[$k];
                    }
                    $v += (float) $newValue;
                    $value = round($v / ($n + 1));
                    if ($value == -0.0) {
                        $value = 0.0;
                    }
                    $this->SendDebug(__FUNCTION__, 'set "' . 'SmoothedSurplusPower' . '" to ' . $value, 0);
                    $this->SetValue('SmoothedSurplusPower', $value);
                } else {
                    $this->SendDebug(__FUNCTION__, 'no log-entries', 0);
                }
                break;
            case self::$METHOD_WEIGHTED_MOVING_AVERAGE:
                if ($n) {
                    $v = 0.0;
                    $f = 0;
                    if ($n >= $quantity) {
                        $n = $quantity - 1;
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
                    $this->SendDebug(__FUNCTION__, 'set "' . 'SmoothedSurplusPower' . '" to ' . $value, 0);
                    $this->SetValue('SmoothedSurplusPower', $value);
                } else {
                    $this->SendDebug(__FUNCTION__, 'no log-entries', 0);
                }
                break;
            default:
                break;
        }

        $this->DelayCalcSurplus();

        IPS_SemaphoreLeave($this->SemaphoreID);
    }

    private function CalcSurplus($surplusUse = null)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        if (IPS_SemaphoreEnter($this->SemaphoreID, self::$semaphoreTM) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to lock sempahore ' . $this->SemaphoreID, 0);
            return;
        }

        if ($surplusUse == null) {
            $surplusUse = $this->GetValue('SurplusUse');
        }

        $smoothed_surplus = $this->GetValue('SmoothedSurplusPower');

        $storage_discharge_varID = $this->ReadPropertyInteger('storage_discharge_varID');
        $storage_discharge_unit = $this->ReadPropertyInteger('storage_discharge_unit');

        if (IPS_VariableExists($storage_discharge_varID)) {
            $storage_discharge = round(GetValueFloat($storage_discharge_varID) * $storage_discharge_unit);
        } else {
            $storage_discharge = 0;
        }

        $this->SendDebug(__FUNCTION__, 'smoothed_surplus=' . $smoothed_surplus . 'W, storage_discharge=' . $storage_discharge . 'W', 0);

        $storage_soc_varID = $this->ReadPropertyInteger('storage_soc_varID');
        $storage_soc_unit = $this->ReadPropertyInteger('storage_soc_unit');

        $charge_reduce = 0;
        if (IPS_VariableExists($storage_soc_varID)) {
            $charge_priority = $this->GetValue('ChargePriority');
            if ($charge_priority != self::$CHARGE_PRIORITY_NONE) {
                $storage_soc = (float) GetValue($storage_soc_varID) * $storage_soc_unit;
                $storage_charging_power = json_decode($this->ReadPropertyString('storage_charging_power'), true);
                if ($storage_charging_power != false) {
                    usort($storage_charging_power, function ($a, $b)
                    {
                        return ($a['limit'] < $b['limit']) ? -1 : 1;
                    });
                    $soc_limit = 0;
                    foreach ($storage_charging_power as $ent) {
                        $soc_limit = $ent['limit'];
                        if ($soc_limit > $storage_soc) {
                            switch ($charge_priority) {
                                case self::$CHARGE_PRIORITY_NORMAL:
                                    $charge_reduce = $ent['normal'];
                                    break;
                                case self::$CHARGE_PRIORITY_HIGH:
                                    $charge_reduce = $ent['high'];
                                    break;
                                case self::$CHARGE_PRIORITY_LOW:
                                    $charge_reduce = $ent['low'];
                                    break;
                                default:
                                    break;
                            }
                            break;
                        }
                    }
                    $charge_priority_s = GetValueFormatted($this->GetIDForIdent('ChargePriority'));
                    $this->SendDebug(__FUNCTION__, 'soc=' . $storage_soc . '%, priority=' . $charge_priority_s . ' => charge_reduce=' . $charge_reduce . 'W', 0);
                }
            }
        }

        $ev_supported_phases = $this->ReadPropertyString('ev_supported_phases');
        $ev_current_min = $this->ReadPropertyInteger('ev_current_min');
        $ev_current_max = $this->ReadPropertyInteger('ev_current_max');
        $ev_phases_varID = $this->ReadPropertyInteger('ev_phases_varID');

        $ev_actual_power_varID = $this->ReadPropertyInteger('ev_actual_power_varID');
        $ev_actual_power_unit = $this->ReadPropertyInteger('ev_actual_power_unit');

        if (IPS_VariableExists($ev_actual_power_varID)) {
            $ev_actual_power = round(GetValueFloat($ev_actual_power_varID) * $ev_actual_power_unit);
            $this->SendDebug(__FUNCTION__, 'ev_actual_power=' . $ev_actual_power . 'W', 0);
        } else {
            $ev_actual_power = 0;
        }

        if (IPS_VariableExists($ev_phases_varID)) {
            $ev_phases = GetValueInteger($ev_phases_varID);

            $power_min = $ev_phases * $ev_current_min * 230;
            $power_max = $ev_phases * $ev_current_max * 230;
            $this->SendDebug(__FUNCTION__, 'ev_phases=' . $ev_phases . ', power=' . $power_min . 'W..' . $power_max . 'W', 0);
        }

        $usable_surplus = max($smoothed_surplus - $storage_discharge - $charge_reduce, 0);

        $this->SendDebug(__FUNCTION__, 'set "' . 'UsableSurplusPower' . '" to ' . $usable_surplus, 0);
        $this->SetValue('UsableSurplusPower', $usable_surplus);

        $this->MaintainTimer('CalcSurplus', 0);

        IPS_SemaphoreLeave($this->SemaphoreID);
    }
}
