<?php

// Declare
declare(strict_types=1);

trait BAT_notification
{
    /**
     * Resets the blacklist for immediate notification.
     */
    public function ResetBlacklist(): void
    {
        $this->WriteAttributeString('Blacklist', '{"normalStatus":false,"criticalStatus":false}');
        $this->SetResetBlacklistTimer();
    }

    /**
     * Triggers the daily notification.
     *
     * @param bool $ResetCriticalVariables
     * false    = keep critical variables
     * true     = reset critical variables
     */
    public function TriggerDailyNotification(bool $ResetCriticalVariables): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $this->SendDebug(__FUNCTION__, 'Parameter $ResetCriticalVariables = ' . json_encode($ResetCriticalVariables), 0);
        $this->SetDailyNotificationTimer();
        // Monitoring must be activated
        if (!$this->GetValue('Monitoring')) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Die Überwachung ist deaktiviert!', 0);
        }
        // Daily notification must be activated
        if (!$this->ReadPropertyBoolean('DailyNotification')) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Die tägliche Benachrichtigung ist deaktiviert!', 0);
        }
        // Monitoring must be activated
        if ($this->GetValue('Monitoring')) {
            // Daily notification must be activated
            if ($this->ReadPropertyBoolean('DailyNotification')) {
                // Notification center must be valid
                $notificationCenter = $this->ReadPropertyInteger('NotificationCenter');
                if ($notificationCenter != 0 && IPS_ObjectExists($notificationCenter)) {
                    if (IPS_GetInstance($notificationCenter)['ModuleInfo']['ModuleID'] != self::NOTIFICATION_CENTER_GUID) {
                        $this->SendDebug(__FUNCTION__, 'Abbruch, Die Benachrichtigungszentrale ist ungültig!', 0);
                        return;
                    }
                    $this->SendDebug(__FUNCTION__, 'Die tägliche Benachrichtigung wird erstellt, Benachrichtigungen werden versendet.', 0);
                    $notification = true;
                    $unicode = json_decode('"\ud83d\udfe2"'); // green_circle
                    $text = 'Batterieüberwachung: ' . $unicode . ' OK!';
                    $actualStatus = $this->GetValue('Status');
                    if ($actualStatus) {
                        $unicode = json_decode('"\ud83d\udd34"'); // red_circle
                        $text = 'Batterieüberwachung: ' . $unicode . ' Alarm!';
                    }
                    if (!$actualStatus && $this->ReadPropertyBoolean('DailyNotificationOnlyOnAlarm')) {
                        $notification = false;
                    }
                    if ($notification) {
                        $location = $this->ReadPropertyString('Location');
                        $timeStamp = date('d.m.Y, H:i:s');
                        // Push notification
                        if ($this->ReadPropertyBoolean('DailyNotificationUsePushNotification')) {
                            $pushTitle = substr($location, 0, 32);
                            $pushText = "\n" . $text . "\n" . $timeStamp;
                            @BENA_SendPushNotification($notificationCenter, $pushTitle, $pushText, 4);
                        }
                        // Email notification
                        if ($this->ReadPropertyBoolean('DailyNotificationUseEmailNotification')) {
                            $emailSubject = 'Batterieüberwachung ' . $location . ', Tagesbericht vom ' . $timeStamp;
                            $emailText = $this->CreateEmailReportText(1);
                            @BENA_SendEMailNotification($notificationCenter, $emailSubject, $emailText, 4);
                        }
                        // SMS Notification
                        if ($this->ReadPropertyBoolean('DailyNotificationUseSMSNotification')) {
                            $smsText = $location . "\n" . $text . "\n" . $timeStamp;
                            @BENA_SendSMSNotification($notificationCenter, $smsText, 4);
                        }
                    }
                }
            }
        }
        if ($ResetCriticalVariables) {
            $this->ResetCriticalVariablesForDailyNotification();
        }
    }

    /**
     * Resets the critical variables for daily notification.
     */
    public function ResetCriticalVariablesForDailyNotification(): void
    {
        $criticalStateVariables = json_decode($this->ReadAttributeString('CriticalStateVariables'), true);
        array_splice($criticalStateVariables['dailyNotification'], 0);
        $this->WriteAttributeString('CriticalStateVariables', json_encode($criticalStateVariables));
    }

    /**
     * Triggers the weekly notification.
     *
     * @param bool $CheckDay
     * false    = trigger notification
     * true     = check weekday
     *
     * @param bool $ResetCriticalVariables
     * false    = keep critical variables
     * true     = reset critical variables
     */
    public function TriggerWeeklyNotification(bool $CheckDay, bool $ResetCriticalVariables): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $this->SendDebug(__FUNCTION__, 'Parameter $CheckDay = ' . json_encode($CheckDay), 0);
        $this->SendDebug(__FUNCTION__, 'Parameter $ResetCriticalVariables = ' . json_encode($ResetCriticalVariables), 0);
        $this->SetWeeklyNotificationTimer();
        // Monitoring must be activated
        if (!$this->GetValue('Monitoring')) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Die Überwachung ist deaktiviert!', 0);
        }
        // Weekly notification must be activated
        if (!$this->ReadPropertyBoolean('WeeklyNotification')) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Die tägliche Benachrichtigung ist deaktiviert!', 0);
        }
        // Check weekday
        $weekday = date('w');
        if ($weekday == $this->ReadPropertyInteger('WeeklyNotificationDay') || !$CheckDay) {
            // Monitoring must be activated
            if ($this->GetValue('Monitoring')) {
                // Weekly notification must be activated
                if ($this->ReadPropertyBoolean('WeeklyNotification')) {
                    // Notification center must be valid
                    $notificationCenter = $this->ReadPropertyInteger('NotificationCenter');
                    if ($notificationCenter != 0 && IPS_ObjectExists($notificationCenter)) {
                        if (IPS_GetInstance($notificationCenter)['ModuleInfo']['ModuleID'] != self::NOTIFICATION_CENTER_GUID) {
                            $this->SendDebug(__FUNCTION__, 'Abbruch, Die Benachrichtigungszentrale ist ungültig!', 0);
                            return;
                        }
                        $this->SendDebug(__FUNCTION__, 'Die wöchentliche Benachrichtigung wird erstellt, Benachrichtigungen werden versendet.', 0);
                        $notification = true;
                        $unicode = json_decode('"\ud83d\udfe2"'); // green_circle
                        $text = 'Batterieüberwachung: ' . $unicode . ' OK!';
                        $actualStatus = $this->GetValue('Status');
                        if ($actualStatus) {
                            $unicode = json_decode('"\ud83d\udd34"'); // red_circle
                            $text = 'Batterieüberwachung: ' . $unicode . ' Alarm!';
                        }
                        if (!$actualStatus && $this->ReadPropertyBoolean('WeeklyNotificationOnlyOnAlarm')) {
                            $notification = false;
                        }
                        if ($notification) {
                            $location = $this->ReadPropertyString('Location');
                            $timeStamp = date('d.m.Y, H:i:s');
                            // Push notification
                            if ($this->ReadPropertyBoolean('WeeklyNotificationUsePushNotification')) {
                                $pushTitle = substr($location, 0, 32);
                                $pushText = "\n" . $text . "\n" . $timeStamp;
                                @BENA_SendPushNotification($notificationCenter, $pushTitle, $pushText, 4);
                            }
                            // Email notification
                            if ($this->ReadPropertyBoolean('WeeklyNotificationUseEmailNotification')) {
                                $emailSubject = 'Batterieüberwachung ' . $location . ', Wochenbericht vom ' . $timeStamp;
                                $emailText = $this->CreateEmailReportText(2);
                                @BENA_SendEMailNotification($notificationCenter, $emailSubject, $emailText, 4);
                            }
                            // SMS Notification
                            if ($this->ReadPropertyBoolean('WeeklyNotificationUseSMSNotification')) {
                                $smsText = $location . "\n" . $text . "\n" . $timeStamp;
                                @BENA_SendSMSNotification($notificationCenter, $smsText, 4);
                            }
                        }
                    }
                }
            }
            if ($ResetCriticalVariables) {
                $this->ResetCriticalVariablesForWeeklyNotification();
            }
        }
    }

    /**
     * Resets the critical variables for weekly notification.
     */
    public function ResetCriticalVariablesForWeeklyNotification(): void
    {
        $criticalStateVariables = json_decode($this->ReadAttributeString('CriticalStateVariables'), true);
        array_splice($criticalStateVariables['weeklyNotification'], 0);
        $this->WriteAttributeString('CriticalStateVariables', json_encode($criticalStateVariables));
    }

    //#################### Private

    /**
     * Triggers the immediate notification.
     */
    private function TriggerImmediateNotification(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        // Monitoring must be activated
        if (!$this->GetValue('Monitoring')) {
            $this->ResetCriticalVariablesForImmediateNotification();
            $this->SendDebug(__FUNCTION__, 'Abbruch, Die Überwachung ist deaktiviert!', 0);
            return;
        }
        // Immediate notification must be activated
        if (!$this->ReadPropertyBoolean('ImmediateNotification')) {
            $this->ResetCriticalVariablesForImmediateNotification();
            $this->SendDebug(__FUNCTION__, 'Abbruch, Die sofortige Benachrichtigung ist deaktiviert!', 0);
            return;
        }
        // Notification center must be valid
        $notificationCenter = $this->ReadPropertyInteger('NotificationCenter');
        if ($notificationCenter != 0 && IPS_ObjectExists($notificationCenter)) {
            if (IPS_GetInstance($notificationCenter)['ModuleInfo']['ModuleID'] != self::NOTIFICATION_CENTER_GUID) {
                $this->SendDebug(__FUNCTION__, 'Abbruch, Die Benachrichtigungszentrale ist ungültig!', 0);
                return;
            }
            $this->SendDebug(__FUNCTION__, 'Die sofortige Benachrichtigung wird erstellt, Benachrichtigungen werden versendet.', 0);
            $unicode = json_decode('"\ud83d\udfe2"'); // green_circle
            $text = 'Batterieüberwachung: ' . $unicode . ' OK!';
            $actualStatus = $this->GetValue('Status');
            if ($actualStatus) {
                $unicode = json_decode('"\ud83d\udd34"'); // red_circle
                $text = 'Batterieüberwachung: ' . $unicode . ' Alarm!';
            }
            if (!$actualStatus && $this->ReadPropertyBoolean('ImmediateNotificationOnlyOnAlarm')) {
                return;
            }
            $blacklist = json_decode($this->ReadAttributeString('Blacklist'), true);
            if ($this->ReadPropertyBoolean('ImmediateNotificationMaximumOncePerDay')) {
                $normalStatus = $blacklist['normalStatus'];
                if (!$actualStatus && $normalStatus) {
                    return;
                }
                if (!$actualStatus && !$normalStatus) {
                    $blacklist['normalStatus'] = true;
                }
                $criticalStatus = $blacklist['criticalStatus'];
                if ($actualStatus && $criticalStatus) {
                    return;
                }
                if ($actualStatus && !$criticalStatus) {
                    $blacklist['criticalStatus'] = true;
                }
            }
            $location = $this->ReadPropertyString('Location');
            $timeStamp = date('d.m.Y, H:i:s');
            // Push notification
            if ($this->ReadPropertyBoolean('ImmediateNotificationUsePushNotification')) {
                $pushTitle = substr($location, 0, 32);
                $pushText = "\n" . $text . "\n" . $timeStamp;
                @BENA_SendPushNotification($notificationCenter, $pushTitle, $pushText, 4);
            }
            // Email notification
            if ($this->ReadPropertyBoolean('ImmediateNotificationUseEmailNotification')) {
                $emailSubject = 'Batterieüberwachung ' . $location . ', Sofortige Benachrichtigung vom ' . $timeStamp;
                $emailText = $this->CreateEmailReportText(0);
                @BENA_SendEMailNotification($notificationCenter, $emailSubject, $emailText, 4);
            }
            // SMS Notification
            if ($this->ReadPropertyBoolean('ImmediateNotificationUseSMSNotification')) {
                $smsText = $location . "\n" . $text . "\n" . $timeStamp;
                @BENA_SendSMSNotification($notificationCenter, $smsText, 4);
            }
            $this->WriteAttributeString('Blacklist', json_encode($blacklist));
            $this->ResetCriticalVariablesForImmediateNotification();
        }
    }

    /**
     * Resets the critical variables for immediate notification.
     */
    private function ResetCriticalVariablesForImmediateNotification(): void
    {
        $criticalStateVariables = json_decode($this->ReadAttributeString('CriticalStateVariables'), true);
        array_splice($criticalStateVariables['immediateNotification'], 0);
        $this->WriteAttributeString('CriticalStateVariables', json_encode($criticalStateVariables));
    }

    /**
     * Creates the email report text.
     *
     * @param int $NotificationType
     * 0    = immediate notification
     * 1    = daily notification
     * 2    = weekly notification
     *
     * @return string
     */
    private function CreateEmailReportText(int $NotificationType): string
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $this->SendDebug(__FUNCTION__, 'Parameter $NotificationType = ' . json_encode($NotificationType), 0);
        $criticalStateVariables = json_decode($this->ReadAttributeString('CriticalStateVariables'), true);
        switch ($NotificationType) {
            // Immediate notification
            case 0:
                $criticalVariables = $criticalStateVariables['immediateNotification'];
                break;

            // Daily notification
            case 1:
                $criticalVariables = $criticalStateVariables['dailyNotification'];
                break;

            // Weekly notification
            case 2:
                $criticalVariables = $criticalStateVariables['weeklyNotification'];
                break;

        }
        $statusValue = $this->GetValue('Status');
        $unicode = json_decode('"\u2705"'); // white_check_mark
        if ($statusValue) {
            $unicode = json_decode('"\ud83d\udea8"'); // rotating_light
        }
        $statusText = GetValueFormatted($this->GetIDForIdent('Status'));
        $text = "Aktueller Batteriestatus:\n\n" . $unicode . ' ' . $statusText . "\n";
        // Variables with a critical state exist
        if (!empty($criticalVariables)) {
            // Sort variables by name
            usort($criticalVariables, function ($a, $b)
            {
                return $a['name'] <=> $b['name'];
            });
            // Rebase array
            $criticalVariables = array_values($criticalVariables);
            // Update overdue first
            $updateOverviewAmount = 0;
            foreach ($criticalVariables as $variable) {
                if ($variable['actualStatus'] == 2) {
                    $updateOverviewAmount++;
                }
            }
            if ($updateOverviewAmount > 0) {
                $unicode = json_decode('"\u2757"'); // heavy_exclamation_mark
                $text .= "\n\n\n\n";
                $text .= "Überfällige Aktualisierung:\n\n";
                foreach ($criticalVariables as $variable) {
                    if ($variable['actualStatus'] == 2) {
                        $text .= $unicode . ' ' . $variable['name'] . ' (ID ' . $variable['id'] . ', ' . $variable['comment'] . ', ' . $variable['timestamp'] . ")\n";
                    }
                }
            }
            // Low battery next
            $lowBatteryAmount = 0;
            foreach ($criticalVariables as $variable) {
                if ($variable['actualStatus'] == 1) {
                    $lowBatteryAmount++;
                }
            }
            if ($lowBatteryAmount > 0) {
                $unicode = json_decode('"\u26a0\ufe0f"'); // warning
                $text .= "\n\n\n\n";
                $text .= "Schwache Batterie:\n\n";
                foreach ($criticalVariables as $variable) {
                    if ($variable['actualStatus'] == 1) {
                        $text .= $unicode . ' ' . $variable['name'] . ' (ID ' . $variable['id'] . ', ' . $variable['comment'] . ', ' . $variable['timestamp'] . ")\n";
                    }
                }
            }
        }
        // Battery OK
        $monitoredVariables = json_decode($this->ReadPropertyString('MonitoredVariables'), true);
        if (!empty($monitoredVariables)) {
            usort($monitoredVariables, function ($a, $b)
            {
                return $a['Name'] <=> $b['Name'];
            });
            $monitoredVariables = array_values($monitoredVariables);
            $variableAmount = 0;
            foreach ($monitoredVariables as $variable) {
                $id = $variable['ID'];
                if ($id != 0 && IPS_ObjectExists($id)) {
                    // Check low battery
                    $lowBattery = true;
                    if ($variable['CheckBattery']) {
                        $actualValue = boolval(GetValue($id));
                        $alertingValue = boolval($variable['AlertingValue']);
                        if ($actualValue != $alertingValue) {
                            $lowBattery = false;
                        }
                    } else {
                        $lowBattery = false;
                    }
                    // Check update overdue
                    $updateOverdue = true;
                    if ($variable['CheckUpdate']) {
                        $now = time();
                        $variableUpdate = IPS_GetVariable($id)['VariableUpdated'];
                        $dateDifference = ($now - $variableUpdate) / (60 * 60 * 24);
                        if ($dateDifference <= $variable['UpdatePeriod']) {
                            $updateOverdue = false;
                        }
                    } else {
                        $updateOverdue = false;
                    }
                    if (!$lowBattery && !$updateOverdue) {
                        $variableAmount++;
                    }
                }
            }
            if ($variableAmount > 0) {
                $unicode = json_decode('"\u2705"'); // white_check_mark
                $text .= "\n\n\n\n";
                $text .= "Batterie OK:\n\n";
                $timeStamp = date('d.m.Y, H:i:s');
                foreach ($monitoredVariables as $variable) {
                    $id = $variable['ID'];
                    if ($id != 0 && IPS_ObjectExists($id)) {
                        // Check low battery
                        $lowBattery = true;
                        if ($variable['CheckBattery']) {
                            $actualValue = boolval(GetValue($id));
                            $alertingValue = boolval($variable['AlertingValue']);
                            if ($actualValue != $alertingValue) {
                                $lowBattery = false;
                            }
                        } else {
                            $lowBattery = false;
                        }
                        // Check update overdue
                        $updateOverdue = true;
                        if ($variable['CheckUpdate']) {
                            $now = time();
                            $variableUpdate = IPS_GetVariable($id)['VariableUpdated'];
                            $dateDifference = ($now - $variableUpdate) / (60 * 60 * 24);
                            if ($dateDifference <= $variable['UpdatePeriod']) {
                                $updateOverdue = false;
                            }
                        } else {
                            $updateOverdue = false;
                        }
                        if (!$lowBattery && !$updateOverdue) {
                            $text .= $unicode . ' ' . $variable['Name'] . ' (ID ' . $id . ', ' . $variable['Comment'] . ', ' . $timeStamp . ")\n";
                        }
                    }
                }
            }
        }
        return $text;
    }
}