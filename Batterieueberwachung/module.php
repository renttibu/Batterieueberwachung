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
 * @version     4.01-25
 * @date        2020-03-17, 18:00, 1584464400
 * @review      2020-03-17, 18:00
 *
 * @see         https://github.com/ubittner/Batterieueberwachung/
 *
 * @guids       Library
 *              {33FD5726-16B7-67D3-7E09-3AEC76466CB8}
 *
 *              Batterieueberwachung
 *             	{3E34CE2F-B59B-8634-DF27-0293F2B700FF}
 */

/*
 * Monitoring:
 * Monitoring is always performed.
 * If "Monitoring" is disabled in WebFront, it has no effect on monitoring or battery list, just no notification will be sent.
 *
 * Critical status:
 * Low battery will only determined as low battery, if "CheckBattery" from configuration form is enabled.
 * Update overdue will only determined as update overdue, if "CheckUpdate" from configuration form is enabled.
 *
 * Notification:
 * When the overall status changes, a notification will be sent.
 * Push notification will only send the actual status at execution time.
 * SMS notification will only send the actual status at execution time.
 * Email notification will send a detailed report at execution time and mode.
 */

// Declare
declare(strict_types=1);

// Include
include_once __DIR__ . '/helper/autoload.php';

class Batterieueberwachung extends IPSModule
{
    // Helper
    use BAT_backupRestore;
    use BAT_notification;
    use BAT_variables;

    // Constants
    private const HOMEMATIC_DEVICE_GUID = '{EE4A81C6-5C90-4DB7-AD2F-F6BBD521412E}';
    private const NOTIFICATION_CENTER_GUID = '{D184C522-507F-BED6-6731-728CE156D659}';

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
        $this->SetDailyNotificationTimer();
        $this->SetWeeklyNotificationTimer();

        // Set Options
        $this->SetOptions();

        // Reset blacklist
        $this->ResetBlacklist();

        // Check status
        $this->CheckMonitoredVariables(0);

        // Clean up critical state variables
        $this->CleanUpCriticalStateVariables();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        // Send debug
        // $Data[0] = actual value
        // $Data[1] = value changed
        // $Data[2] = last value
        $this->SendDebug(__FUNCTION__, 'SenderID: ' . $SenderID . ', Message: ' . $Message . ', Data: ' . print_r($Data, true), 0);
        if (!empty($Data)) {
            foreach ($Data as $key => $value) {
                $this->SendDebug(__FUNCTION__, 'Data[' . $key . '] = ' . json_encode($value), 0);
            }
        }
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->KernelReady();
                break;

            case VM_UPDATE:
               $this->CheckMonitoredVariables(0);
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
        $formData = json_decode(file_get_contents(__DIR__ . '/form.json'));
        // Monitored variables
        $monitoredVariables = json_decode($this->ReadPropertyString('MonitoredVariables'), true);
        if (!empty($monitoredVariables)) {
            foreach ($monitoredVariables as $variable) {
                $rowColor = '';
                $unicode = json_decode('"\u2705"'); // white_check_mark
                $actualValue = 0;
                $lastUpdate = 'Nie';
                if (!IPS_ObjectExists($variable['ID'])) {
                    $unicode = '';
                    $rowColor = '#FFC0C0'; // light red
                } else {
                    // Check battery
                    $actualValue = boolval(GetValue($variable['ID']));
                    if ($variable['CheckBattery']) {
                        $alertingValue = boolval($variable['AlertingValue']);
                        if ($actualValue == $alertingValue) {
                            $unicode = json_decode('"\u26a0\ufe0f"'); // warning
                        }
                    }
                    // Check update
                    $variableUpdate = IPS_GetVariable($variable['ID'])['VariableUpdated'];
                    if ($variableUpdate != 0) {
                        $lastUpdate = date('d.m.Y', $variableUpdate);
                    }
                    if ($variable['CheckUpdate']) {
                        if ($variableUpdate == 0) {
                            $unicode = json_decode('"\u2757"'); // heavy_exclamation_mark
                        }
                        $now = time();
                        $dateDifference = ($now - $variableUpdate) / (60 * 60 * 24);
                        $updatePeriod = $variable['UpdatePeriod'];
                        if ($dateDifference > $updatePeriod) {
                            $unicode = json_decode('"\u2757"'); // heavy_exclamation_mark
                        }
                    }
                }
                $formData->elements[2]->items[1]->values[] = [
                    'ActualStatus'            => $unicode,
                    'ID'                      => $variable['ID'],
                    'Name'                    => $variable['Name'],
                    'Comment'                 => $variable['Comment'],
                    'CheckBattery'            => $variable['CheckBattery'],
                    'ActualValue'             => $actualValue,
                    'AlertingValue'           => $variable['AlertingValue'],
                    'CheckUpdate'             => $variable['CheckUpdate'],
                    'UpdatePeriod'            => $variable['UpdatePeriod'],
                    'LastUpdate'              => $lastUpdate,
                    'LastBatteryReplacement'  => $variable['LastBatteryReplacement'],
                    'rowColor'                => $rowColor];
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
                $description = $senderName;
                $parentID = IPS_GetParent($senderID);
                if (is_int($parentID) && $parentID != 0 && @IPS_ObjectExists($parentID)) {
                    $description = IPS_GetName($parentID);
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
            $formData->actions[1]->items[0]->values[] = [
                'Description'         => $description,
                'SenderID'            => $senderID,
                'SenderName'          => $senderName,
                'MessageID'           => $messageID,
                'MessageDescription'  => $messageDescription];
        }
        // Blacklist
        $blacklist = json_decode($this->ReadAttributeString('Blacklist'), true);
        if (!empty($blacklist)) {
            $text = 'erlaubt';
            $normalStatus = $blacklist['normalStatus'];
            if ($normalStatus) {
                $text = 'gesperrt';
            }
            $formData->actions[3]->items[0]->values[] = [
                'Status'                => 'OK',
                'Notification'          => $text];
            $criticalStatus = $blacklist['criticalStatus'];
            if ($criticalStatus) {
                $text = 'gesperrt';
            }
            $formData->actions[3]->items[0]->values[] = [
                'Status'                => 'Alarm',
                'Notification'          => $text];
        }
        // Daily critical variables
        $criticalVariables = json_decode($this->ReadAttributeString('CriticalStateVariables'), true)['dailyNotification'];
        if (!empty($criticalVariables)) {
            foreach ($criticalVariables as $variable) {
                $actualStatus = $variable['actualStatus'];
                if ($actualStatus != 0) {
                    $unicode = json_decode('"\u26a0\ufe0f"'); // warning
                    if ($actualStatus == 2) {
                        $unicode = json_decode('"\u2757"'); // heavy_exclamation_mark
                    }
                    $formData->actions[4]->items[0]->values[] = [
                        'ActualStatus' => $unicode,
                        'ID'           => $variable['id'],
                        'Name'         => $variable['name'],
                        'Comment'      => $variable['comment'],
                        'Timestamp'    => $variable['timestamp']];
                }
            }
        }
        // Weekly critical variables
        $criticalVariables = json_decode($this->ReadAttributeString('CriticalStateVariables'), true)['weeklyNotification'];
        if (!empty($criticalVariables)) {
            foreach ($criticalVariables as $variable) {
                $actualStatus = $variable['actualStatus'];
                if ($actualStatus != 0) {
                    $unicode = json_decode('"\u26a0\ufe0f"'); // warning
                    if ($actualStatus == 2) {
                        $unicode = json_decode('"\u2757"'); // heavy_exclamation_mark
                    }
                    $formData->actions[5]->items[0]->values[] = [
                        'ActualStatus' => $unicode,
                        'ID'           => $variable['id'],
                        'Name'         => $variable['name'],
                        'Comment'      => $variable['comment'],
                        'Timestamp'    => $variable['timestamp']];
                }
            }
        }
        return json_encode($formData);
    }

    //#################### Request action

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Monitoring':
                $this->SetValue('Monitoring', $Value);
                $this->CheckMonitoredVariables(0);
                break;

            case 'BatteryReplacement':
                $this->UpdateBatteryReplacement($Value);
                break;

        }
    }

    //#################### Private

    private function RegisterProperties(): void
    {
        // Visibility
        $this->RegisterPropertyBoolean('EnableMonitoring', true);
        $this->RegisterPropertyBoolean('EnableStatus', true);
        $this->RegisterPropertyBoolean('EnableBatteryReplacement', true);
        $this->RegisterPropertyBoolean('EnableBatteryList', true);

        // Monitored variables
        $this->RegisterPropertyString('MonitoredVariables', '[]');

        // Notification
        $this->RegisterPropertyString('Location', '');
        $this->RegisterPropertyInteger('NotificationCenter', 0);
        $this->RegisterPropertyBoolean('ImmediateNotification', true);
        $this->RegisterPropertyBoolean('ImmediateNotificationOnlyOnAlarm', true);
        $this->RegisterPropertyBoolean('ImmediateNotificationMaximumOncePerDay', true);
        $this->RegisterPropertyString('ResetBlacklistTime', '{"hour":7,"minute":0,"second":0}');
        $this->RegisterPropertyBoolean('ImmediateNotificationUsePushNotification', true);
        $this->RegisterPropertyBoolean('ImmediateNotificationUseEmailNotification', true);
        $this->RegisterPropertyBoolean('ImmediateNotificationUseSMSNotification', true);
        $this->RegisterPropertyBoolean('DailyNotification', true);
        $this->RegisterPropertyString('DailyNotificationTime', '{"hour":19,"minute":0,"second":0}');
        $this->RegisterPropertyBoolean('DailyNotificationOnlyOnAlarm', true);
        $this->RegisterPropertyBoolean('DailyNotificationUsePushNotification', true);
        $this->RegisterPropertyBoolean('DailyNotificationUseEmailNotification', true);
        $this->RegisterPropertyBoolean('DailyNotificationUseSMSNotification', true);
        $this->RegisterPropertyBoolean('WeeklyNotification', false);
        $this->RegisterPropertyInteger('WeeklyNotificationDay', 0);
        $this->RegisterPropertyString('WeeklyNotificationTime', '{"hour":19,"minute":0,"second":0}');
        $this->RegisterPropertyBoolean('WeeklyNotificationOnlyOnAlarm', false);
        $this->RegisterPropertyBoolean('WeeklyNotificationUsePushNotification', true);
        $this->RegisterPropertyBoolean('WeeklyNotificationUseEmailNotification', true);
        $this->RegisterPropertyBoolean('WeeklyNotificationUseSMSNotification', true);
        $this->RegisterPropertyInteger('NotificationScript', 0);
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
        // Battery boolean
        $profile = 'BAT.Battery.Boolean';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'OK', 'Information', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 1, 'Batterie schwach', 'Battery', 0xFF0000);
        // Battery integer
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
        // Battery replacement
        $this->RegisterVariableInteger('BatteryReplacement', 'Batteriewechsel ID', '', 2);
        $this->EnableAction('BatteryReplacement');
        IPS_SetIcon($this->GetIDForIdent('BatteryReplacement'), 'Gear');
        // Battery list
        $this->RegisterVariableString('BatteryList', 'Batterieliste', 'HTMLBox', 3);
        IPS_SetIcon($this->GetIDForIdent('BatteryList'), 'Battery');
    }

    private function SetOptions(): void
    {
        // Monitoring
        IPS_SetHidden($this->GetIDForIdent('Monitoring'), !$this->ReadPropertyBoolean('EnableMonitoring'));
        // Status
        IPS_SetHidden($this->GetIDForIdent('Status'), !$this->ReadPropertyBoolean('EnableStatus'));
        // Battery replacement
        IPS_SetHidden($this->GetIDForIdent('BatteryReplacement'), !$this->ReadPropertyBoolean('EnableBatteryReplacement'));
        // Battery list
        IPS_SetHidden($this->GetIDForIdent('BatteryList'), !$this->ReadPropertyBoolean('EnableBatteryList'));
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
                if ($variable->CheckBattery) {
                    if ($variable->ID != 0 && @IPS_ObjectExists($variable->ID)) {
                        $this->RegisterMessage($variable->ID, VM_UPDATE);
                    }
                }
            }
        }
    }

    private function RegisterTimers(): void
    {
        $this->RegisterTimer('ResetBlacklist', 0, 'BAT_ResetBlacklist(' . $this->InstanceID . ');');
        $this->RegisterTimer('DailyNotification', 0, 'BAT_TriggerDailyNotification(' . $this->InstanceID . ', true);');
        $this->RegisterTimer('WeeklyNotification', 0, 'BAT_TriggerWeeklyNotification(' . $this->InstanceID . ', true, true);');
    }

    private function SetResetBlacklistTimer(): void
    {
        $this->SetTimerInterval('ResetBlacklist', $this->GetInterval('ResetBlacklistTime'));
    }

    private function SetDailyNotificationTimer(): void
    {
        $this->SetTimerInterval('DailyNotification', $this->GetInterval('DailyNotificationTime'));
    }

    private function SetWeeklyNotificationTimer(): void
    {
        $this->SetTimerInterval('WeeklyNotification', $this->GetInterval('WeeklyNotificationTime'));
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
        $this->RegisterAttributeString('Blacklist', '{"normalStatus":false,"criticalStatus":false}');
        $this->RegisterAttributeString('CriticalStateVariables', '{"immediateNotification":[],"dailyNotification":[],"weeklyNotification":[]}');
    }
}
