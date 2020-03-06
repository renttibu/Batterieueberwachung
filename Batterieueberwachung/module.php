<?php

/*
 * @module      Batterieueberwachung
 *
 * @prefix      BAT
 *
 * @file        module.php
 *
 * @author      Ulrich Bittner
 * @copyright   (c) 2019
 * @license    	CC BY-NC-SA 4.0
 *              https://creativecommons.org/licenses/by-nc-sa/4.0/
 *
 * @version     4.00-1
 * @date        2020-03-06, 18:00, 1583514000
 * @review      2020-03-06, 18:00
 *
 * @see         https://github.com/ubittner/Batterieueberwachung/
 *
 * @guids       Library
 *              {33FD5726-16B7-67D3-7E09-3AEC76466CB8}
 *
 *              Batterieueberwachung
 *             	{3E34CE2F-B59B-8634-DF27-0293F2B700FF}
 */

// Declare
declare(strict_types=1);

// Include
include_once __DIR__ . '/helper/autoload.php';

class Batterieueberwachung extends IPSModule
{
    // Helper
    use BAT_notification;
    use BAT_variables;

    // Constants
    private const HOMEMATIC_DEVICE_GUID = '{EE4A81C6-5C90-4DB7-AD2F-F6BBD521412E}';

    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Register properties
        $this->RegisterProperties();

        // Create profiles
        $this->CreateProfiles();

        // Register variables
        $this->RegisterVariables();

        // Register timers
        $this->RegisterTimers();

        // Register attributes
        $this->RegisterAttributes();
    }

    public function ApplyChanges()
    {
        // Wait until IP-Symcon is started
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        // Never delete this line!
        parent::ApplyChanges();

        // Check runlevel
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }

        // Register messages
        $this->RegisterMessages();

        // Set timer
        $this->SetDailyReportTimer();
        $this->SetWeeklyReportTimer();

        // Set Options
        $this->SetOptions();

        // Update battery list
        $this->UpdateBatteryList();

        // Check actual status
        $this->CheckActualStatus();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        // Send debug
        // $Data[0] = actual value
        // $Data[1] = value changed
        // $Data[2] = last value
        $this->SendDebug(__FUNCTION__, 'SenderID: ' . $SenderID . ', Message: ' . $Message . ', Data: ' . print_r($Data, true), 0);
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->KernelReady();
                break;

            case VM_UPDATE:
                $this->UpdateBatteryList();
                $this->CheckActualStatus();
                // Only if value has changed
                if ($Data[1]) {
                    $this->TriggerAlerting($SenderID, boolval($Data[0]));
                }
                break;

            default:
                break;

        }
    }

    private function KernelReady()
    {
        $this->ApplyChanges();
    }

    public function Destroy()
    {
        // Never delete this line!
        parent::Destroy();

        // Delete profiles
        $this->DeleteProfiles();
    }

    public function ReloadConfiguration()
    {
        $this->ReloadForm();
    }

    public function GetConfigurationForm()
    {
        $formdata = json_decode(file_get_contents(__DIR__ . '/form.json'));
        // Monitored variables
        $monitoredVariables = json_decode($this->ReadPropertyString('MonitoredVariables'), true);
        if (!empty($monitoredVariables)) {
            foreach ($monitoredVariables as $variable) {
                $rowColor = '';
                if (!IPS_ObjectExists($variable['ID'])) {
                    $rowColor = '#FFC0C0'; // light red
                } else {
                    $actualValue = boolval(GetValue($variable['ID']));
                    $alertingValue = boolval($variable['AlertingValue']);
                    if ($actualValue == $alertingValue) {
                        $rowColor = '#FFFFC0'; // light yellow
                    }
                }
                $formdata->elements[2]->items[1]->values[] = [
                    'Use'                                           => $variable['Use'],
                    'ID'                                            => $variable['ID'],
                    'Name'                                          => $variable['Name'],
                    'Address'                                       => $variable['Address'],
                    'AlertingValue'                                 => $variable['AlertingValue'],
                    'LastBatteryReplacementDate'                    => $variable['LastBatteryReplacementDate'],
                    'rowColor'                                      => $rowColor];
            }
        }
        // Registered messages
        $registeredVariables = $this->GetMessageList();
        foreach ($registeredVariables as $senderID => $messageID) {
            if (!IPS_ObjectExists($senderID)) {
                foreach ($messageID as $messageType) {
                    $this->UnregisterMessage($senderID, $messageType);
                }
                continue;
            } else {
                $senderName = IPS_GetName($senderID);
                $parentName = $senderName;
                $parentID = IPS_GetParent($senderID);
                if (is_int($parentID) && $parentID != 0 && @IPS_ObjectExists($parentID)) {
                    $parentName = IPS_GetName($parentID);
                }
            }
            switch ($messageID) {
                case [10001]:
                    $messageDescription = 'IPS_KERNELSTARTED';
                    break;

                case [10603]:
                    $messageDescription = 'VM_UPDATE';
                    break;

                case [10803]:
                    $messageDescription = 'EM_UPDATE';
                    break;

                default:
                    $messageDescription = 'keine Bezeichnung';
            }
            $formdata->elements[4]->items[0]->values[] = [
                'ParentName'                                            => $parentName,
                'SenderID'                                              => $senderID,
                'SenderName'                                            => $senderName,
                'MessageID'                                             => $messageID,
                'MessageDescription'                                    => $messageDescription];
        }
        return json_encode($formdata);
    }

    public function GetDailyAttribute(): string
    {
        return $this->ReadAttributeString('DailyLowBatteryVariables');
    }

    public function GetWeeklyAttribute(): string
    {
        return $this->ReadAttributeString('WeeklyLowBatteryVariables');
    }

    //#################### Request action

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Monitoring':
                $this->SetValue('Monitoring', $Value);
                $this->CheckActualStatus();
                break;

            case 'BatteryReplacement':
                $this->UpdateBatteryReplacementDate($Value);
                break;

        }
    }

    //#################### Private

    private function RegisterProperties(): void
    {
        // Visibility
        $this->RegisterPropertyBoolean('EnableMonitoring', true);
        $this->RegisterPropertyBoolean('EnableStatus', true);
        $this->RegisterPropertyBoolean('EnableBatteryList', true);
        $this->RegisterPropertyBoolean('EnableBatteryReplacement', true);
        $this->RegisterPropertyBoolean('CreateLinks', false);
        $this->RegisterPropertyInteger('LinkCategory', 0);

        // Monitored variables
        $this->RegisterPropertyString('MonitoredVariables', '[]');

        // Notification
        $this->RegisterPropertyString('Location', '');
        $this->RegisterPropertyInteger('NotificationCenter', 0);
        $this->RegisterPropertyBoolean('ImmediateNotification', true);
        $this->RegisterPropertyBoolean('ImmediateNotificationOnlyWeakBattery', true);
        $this->RegisterPropertyBoolean('ImmediateNotificationUsePushNotification', true);
        $this->RegisterPropertyBoolean('ImmediateNotificationUseEmailNotification', true);
        $this->RegisterPropertyBoolean('ImmediateNotificationUseSMSNotification', true);
        $this->RegisterPropertyBoolean('DailyReport', true);
        $this->RegisterPropertyBoolean('DailyReportOnlyWeakBattery', true);
        $this->RegisterPropertyBoolean('DailyReportUsePushNotification', true);
        $this->RegisterPropertyBoolean('DailyReportUseEmailNotification', true);
        $this->RegisterPropertyBoolean('DailyReportUseSMSNotification', true);
        $this->RegisterPropertyString('DailyReportTime', '{"hour":19,"minute":0,"second":0}');
        $this->RegisterPropertyBoolean('WeeklyReport', false);
        $this->RegisterPropertyBoolean('WeeklyReportOnlyWeakBattery', false);
        $this->RegisterPropertyBoolean('WeeklyReportUsePushNotification', true);
        $this->RegisterPropertyBoolean('WeeklyReportUseEmailNotification', true);
        $this->RegisterPropertyBoolean('WeeklyReportUseSMSNotification', true);
        $this->RegisterPropertyInteger('WeeklyReportDay', 0);
        $this->RegisterPropertyString('WeeklyReportTime', '{"hour":19,"minute":0,"second":0}');
        $this->RegisterPropertyInteger('NotificationScript', 0);

        // Registered Messages
        $this->RegisterPropertyString('RegisteredMessages', '[]');
    }

    private function CreateProfiles(): void
    {
        // Status
        $profileName = 'BAT.' . $this->InstanceID . '.Status';
        if (!IPS_VariableProfileExists($profileName)) {
            IPS_CreateVariableProfile($profileName, 0);
        }
        IPS_SetVariableProfileAssociation($profileName, 0, 'OK', 'Information', 0x00FF00);
        IPS_SetVariableProfileAssociation($profileName, 1, 'Alarm', 'Warning', 0xFF0000);
        // Boolean (Homematic, Homematic IP)
        $profile = 'BAT.Battery.Boolean';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'OK', 'Information', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 1, 'Batterie schwach', 'Battery', 0xFF0000);
        // Integer
        $profile = 'BAT.Battery.Integer';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'OK', 'Information', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 1, 'Batterie schwach', 'Battery', 0xFF0000);
    }

    private function DeleteProfiles(): void
    {
        $profiles = ['Status'];
        foreach ($profiles as $profile) {
            $profileName = 'BAT.' . $this->InstanceID . '.' . $profile;
            if (@IPS_VariableProfileExists($profileName)) {
                IPS_DeleteVariableProfile($profileName);
            }
        }
    }

    private function RegisterVariables(): void
    {
        // Monitoring
        $this->RegisterVariableBoolean('Monitoring', 'Überwachung', '~Switch', 0);
        $this->EnableAction('Monitoring');
        // Status
        $profile = 'BAT.' . $this->InstanceID . '.Status';
        $this->RegisterVariableBoolean('Status', 'Status', $profile, 1);
        // Overview
        $this->RegisterVariableString('BatteryList', 'Batterieliste', 'HTMLBox', 2);
        IPS_SetIcon($this->GetIDForIdent('BatteryList'), 'Battery');
        // Battery replacement
        $this->RegisterVariableInteger('BatteryReplacement', 'Batteriewechsel ID', '', 3);
        $this->EnableAction('BatteryReplacement');
        IPS_SetIcon($this->GetIDForIdent('BatteryReplacement'), 'Gear');
    }

    private function SetOptions(): void
    {
        // Monitoring
        IPS_SetHidden($this->GetIDForIdent('Monitoring'), !$this->ReadPropertyBoolean('EnableMonitoring'));
        // Status
        IPS_SetHidden($this->GetIDForIdent('Status'), !$this->ReadPropertyBoolean('EnableStatus'));
        // Battery list
        $useBatteryList = $this->ReadPropertyBoolean('EnableBatteryList');
        if ($useBatteryList) {
            $this->UpdateBatteryList();
        }
        IPS_SetHidden($this->GetIDForIdent('BatteryList'), !$useBatteryList);
        // Battery replacement
        IPS_SetHidden($this->GetIDForIdent('BatteryReplacement'), !$this->ReadPropertyBoolean('EnableBatteryReplacement'));
    }

    private function UnregisterMessages(): void
    {
        foreach ($this->GetMessageList() as $id => $registeredMessage) {
            foreach ($registeredMessage as $messageType) {
                if ($messageType == VM_UPDATE) {
                    $this->UnregisterMessage($id, VM_UPDATE);
                }
                if ($messageType == EM_UPDATE) {
                    $this->UnregisterMessage($id, EM_UPDATE);
                }
            }
        }
    }

    private function RegisterMessages(): void
    {
        // Unregister first
        $this->UnregisterMessages();
        // Register variables
        $monitoredVariables = json_decode($this->ReadPropertyString('MonitoredVariables'));
        if (!empty($monitoredVariables)) {
            foreach ($monitoredVariables as $variable) {
                if ($variable->Use) {
                    if ($variable->ID != 0 && @IPS_ObjectExists($variable->ID)) {
                        $this->RegisterMessage($variable->ID, VM_UPDATE);
                    }
                }
            }
        }
    }

    private function RegisterTimers(): void
    {
        $this->RegisterTimer('DailyReport', 0, 'BAT_TriggerDailyReport(' . $this->InstanceID . ', true);');
        $this->RegisterTimer('WeeklyReport', 0, 'BAT_TriggerWeeklyReport(' . $this->InstanceID . ', true, true);');
    }

    private function SetDailyReportTimer(): void
    {
        $timerInterval = 0;
        if ($this->ReadPropertyBoolean('DailyReport')) {
            $timerInterval = $this->GetInterval('DailyReportTime');
        }
        $this->SetTimerInterval('DailyReport', $timerInterval);
    }

    private function SetWeeklyReportTimer(): void
    {
        $timerInterval = 0;
        if ($this->ReadPropertyBoolean('WeeklyReport')) {
            $timerInterval = $this->GetInterval('WeeklyReportTime');
        }
        $this->SetTimerInterval('WeeklyReport', $timerInterval);
    }

    private function GetInterval(string $PropertyName): int
    {
        $now = time();
        $reviewTime = json_decode($this->ReadPropertyString($PropertyName));
        $hour = $reviewTime->hour;
        $minute = $reviewTime->minute;
        $second = $reviewTime->second;
        $definedTime = $hour . ':' . $minute . ':' . $second;
        if (time() >= strtotime($definedTime)) {
            $timestamp = mktime($hour, $minute, $second, (int) date('n'), (int) date('j') + 1, (int) date('Y'));
        } else {
            $timestamp = mktime($hour, $minute, $second, (int) date('n'), (int) date('j'), (int) date('Y'));
        }
        return ($timestamp - $now) * 1000;
    }

    private function RegisterAttributes(): void
    {
        $this->RegisterAttributeString('LowBatteryVariable', '[]');
        $this->RegisterAttributeString('DailyLowBatteryVariables', '[]');
        $this->RegisterAttributeString('WeeklyLowBatteryVariables', '[]');
    }
}
