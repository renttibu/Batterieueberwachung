<?php

// Declare
declare(strict_types=1);

trait BAT_notification
{
    /**
     * Triggers the immediate notification.
     *
     * @param int $SenderID
     * @param bool $ActualValue
     */
    public function TriggerImmediateNotification(int $SenderID, bool $ActualValue): void
    {
        $timeStamp = date('d.m.Y, H:i:s');
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $this->SendDebug(__FUNCTION__, 'Parameter $SenderID = ' . $SenderID, 0);
        $this->SendDebug(__FUNCTION__, 'Parameter $ActualValue = ' . json_encode($ActualValue), 0);
        // Monitoring must be activated
        if (!$this->GetValue('Monitoring')) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Die Überwachung ist deaktiviert!', 0);
            return;
        }
        // Immediate notification must be activated
        if (!$this->ReadPropertyBoolean('ImmediateNotification')) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Die sofortige Benachrichtigung ist deaktiviert!', 0);
            return;
        }
        // Variables must exist
        $monitoredVariables = json_decode($this->ReadPropertyString('MonitoredVariables'), true);
        if (empty($monitoredVariables)) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Es werden keine Variablen überwacht!', 0);
            return;
        }
        $key = array_search($SenderID, array_column($monitoredVariables, 'ID'));
        $name = $monitoredVariables[$key]['Name'];
        $address = $monitoredVariables[$key]['Address'];
        $alertingValue = boolval($monitoredVariables[$key]['AlertingValue']);
        $lowBattery = false;
        if ($ActualValue == $alertingValue) {
            $lowBattery = true;
            // Immediate attribute
            $lowBatteryVariable = json_decode($this->ReadAttributeString('LowBatteryVariable'), true);
            array_push($lowBatteryVariable, ['id' => $SenderID, 'name' => $name, 'timestamp' => $timeStamp, 'address' => $address]);
            $this->WriteAttributeString('LowBatteryVariable', json_encode($lowBatteryVariable));
            // Daily attribute
            if ($this->ReadPropertyBoolean('DailyReport')) {
                $dailyLowBatteryVariables = json_decode($this->ReadAttributeString('DailyLowBatteryVariables'), true);
                array_push($dailyLowBatteryVariables, ['id' => $SenderID, 'name' => $name, 'timestamp' => $timeStamp, 'address' => $address]);
                $this->WriteAttributeString('DailyLowBatteryVariables', json_encode($dailyLowBatteryVariables));
            } else {
                $this->WriteAttributeString('DailyLowBatteryVariables', '[]');
            }
            // Weekly attribute
            if ($this->ReadPropertyBoolean('WeeklyReport')) {
                $weeklyLowBatteryVariables = json_decode($this->ReadAttributeString('WeeklyLowBatteryVariables'), true);
                array_push($weeklyLowBatteryVariables, ['id' => $SenderID, 'name' => $name, 'timestamp' => $timeStamp, 'address' => $address]);
                $this->WriteAttributeString('WeeklyLowBatteryVariables', json_encode($weeklyLowBatteryVariables));
            } else {
                $this->WriteAttributeString('WeeklyLowBatteryVariables', '[]');
            }
        }
        // Notification center must be valid
        $notificationCenter = $this->ReadPropertyInteger('NotificationCenter');
        if ($notificationCenter != 0 && IPS_ObjectExists($notificationCenter)) {
            $this->SendDebug(__FUNCTION__, 'Die sofortigen Benachrichtigungen werden versendet.', 0);
            $location = $this->ReadPropertyString('Location');
            // Battery is ok
            if (!$lowBattery) {
                // Notification is allowed
                if (!$this->ReadPropertyBoolean('ImmediateNotificationOnlyWeakBattery')) {
                    // Push notification
                    if ($this->ReadPropertyBoolean('ImmediateNotificationUsePushNotification')) {
                        $pushTitle = substr($location, 0, 32);
                        $pushText = "\nBatterie OK!\n" . $name . ' (ID ' . $SenderID . ') ' . $timeStamp;
                        @BENA_SendPushNotification($notificationCenter, $pushTitle, $pushText, 4);
                    }
                    // Email notification
                    if ($this->ReadPropertyBoolean('ImmediateNotificationUseEmailNotification')) {
                        $emailSubject = 'Batterieüberwachung ' . $location;
                        $emailText = $this->CreateEmailReportText(0);
                        @BENA_SendEMailNotification($notificationCenter, $emailSubject, $emailText, 4);
                    }
                    // SMS Notification
                    if ($this->ReadPropertyBoolean('ImmediateNotificationUseSMSNotification')) {
                        $smsText = $location . "\nBatterie OK!\n" . $name . ' (ID ' . $SenderID . ') ' . $timeStamp;
                        @BENA_SendSMSNotification($notificationCenter, $smsText, 4);
                    }
                }
            } // Battery is weak
            else {
                // Push notification
                if ($this->ReadPropertyBoolean('ImmediateNotificationUsePushNotification')) {
                    $pushTitle = substr($location, 0, 32);
                    $pushText = "\nBatterie schwach!\n" . $name . ' (ID ' . $SenderID . ') ' . $timeStamp;
                    @BENA_SendPushNotification($notificationCenter, $pushTitle, $pushText, 4);
                }
                // Email notification
                if ($this->ReadPropertyBoolean('ImmediateNotificationUseEmailNotification')) {
                    $emailSubject = 'Batterieüberwachung ' . $location;
                    $emailText = $this->CreateEmailReportText(0);
                    @BENA_SendEMailNotification($notificationCenter, $emailSubject, $emailText, 4);
                }
                // SMS notification
                if ($this->ReadPropertyBoolean('ImmediateNotificationUseSMSNotification')) {
                    $smsText = $location . "\nBatterie schwach!\n" . $name . ' (ID ' . $SenderID . ') ' . $timeStamp;
                    @BENA_SendSMSNotification($notificationCenter, $smsText, 4);
                }
            }
        }
        $this->WriteAttributeString('LowBatteryVariable', '[]');
    }

    /**
     * Resets the attribute for the daily report.
     */
    public function ResetDailyReportAttribute(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $this->WriteAttributeString('DailyLowBatteryVariables', '[]');
    }

    /**
     * Triggers the daily report.
     *
     * @param bool $ResetAttribute
     */
    public function TriggerDailyReport(bool $ResetAttribute): void
    {
        $timeStamp = date('d.m.Y, H:i:s');
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $this->SendDebug(__FUNCTION__, 'Parameter $ResetAttribute = ' . json_encode($ResetAttribute), 0);
        $this->SetDailyReportTimer();
        // Monitoring must be activated
        if (!$this->GetValue('Monitoring')) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Die Überwachung ist deaktiviert!', 0);
            return;
        }
        // Daily report must be activated
        if (!$this->ReadPropertyBoolean('DailyReport')) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Der Tagesbericht ist deaktiviert!', 0);
            return;
        }
        // Notification center must be valid
        $notificationCenter = $this->ReadPropertyInteger('NotificationCenter');
        if ($notificationCenter != 0 && IPS_ObjectExists($notificationCenter)) {
            $this->SendDebug(__FUNCTION__, 'Der Tagesbericht wird erstellt, Benachrichtigungen werden versendet.', 0);
            $date = date('d.m.Y');
            $location = $this->ReadPropertyString('Location');
            $dailyLowBatteryVariables = json_decode($this->ReadAttributeString('DailyLowBatteryVariables'), true);
            // All batteries are ok
            if (empty($dailyLowBatteryVariables)) {
                // Notification is allowed
                if (!$this->ReadPropertyBoolean('DailyReportOnlyWeakBattery')) {
                    // Push notification
                    if ($this->ReadPropertyBoolean('DailyReportUsePushNotification')) {
                        $pushTitle = substr($location, 0, 32);
                        $pushText = "\nAlle Batterien OK!\n" . $timeStamp;
                        @BENA_SendPushNotification($notificationCenter, $pushTitle, $pushText, 4);
                    }
                    // Email notification
                    if ($this->ReadPropertyBoolean('DailyReportUseEmailNotification')) {
                        $emailSubject = 'Batterieüberwachung ' . $location . ', Tagesbericht vom ' . $date;
                        $emailText = $this->CreateEmailReportText(1);
                        @BENA_SendEMailNotification($notificationCenter, $emailSubject, $emailText, 4);
                    }
                    // SMS Notification
                    if ($this->ReadPropertyBoolean('DailyReportUseSMSNotification')) {
                        $smsText = $location . "\nAlle Batterien OK!\n" . $timeStamp;
                        @BENA_SendSMSNotification($notificationCenter, $smsText, 4);
                    }
                }
            }
            // Battery is weak
            else {
                // Push notification
                if ($this->ReadPropertyBoolean('DailyReportUsePushNotification')) {
                    foreach ($dailyLowBatteryVariables as $variable) {
                        $pushTitle = substr($location, 0, 32);
                        $pushText = "\nBatterie schwach!\n" . $variable['name'] . ' (ID ' . $variable['id'] . ') ' . $timeStamp;
                        @BENA_SendPushNotification($notificationCenter, $pushTitle, $pushText, 4);
                    }
                }
                // Email notification
                if ($this->ReadPropertyBoolean('DailyReportUseEmailNotification')) {
                    $emailSubject = 'Batterieüberwachung ' . $location . ', Tagesbericht vom ' . $date;
                    $emailText = $this->CreateEmailReportText(1);
                    @BENA_SendEMailNotification($notificationCenter, $emailSubject, $emailText, 4);
                }
                // SMS notification
                if ($this->ReadPropertyBoolean('DailyReportUseSMSNotification')) {
                    foreach ($dailyLowBatteryVariables as $variable) {
                        $smsText = $location . "\nBatterie schwach!\n" . $variable['name'] . ' (ID ' . $variable['id'] . ') ' . $timeStamp;
                        @BENA_SendSMSNotification($notificationCenter, $smsText, 4);
                    }
                }
            }
            if ($ResetAttribute) {
                $this->ResetDailyReportAttribute();
            }
        }
    }

    /**
     * Resets the attribute for the weekly report.
     */
    public function ResetWeeklyReportAttribute(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $this->WriteAttributeString('WeeklyLowBatteryVariables', '[]');
    }

    /**
     * Triggers the weekly report.
     *
     * @param bool $CheckDay
     * false    = trigger report
     * true     = check weekday
     *
     * @param bool $ResetAttribute
     * false    = keep attributes
     * true     = reset attributes
     */
    public function TriggerWeeklyReport(bool $CheckDay, bool $ResetAttribute): void
    {
        $timeStamp = date('d.m.Y, H:i:s');
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $this->SendDebug(__FUNCTION__, 'Parameter $CheckDay = ' . json_encode($CheckDay), 0);
        $this->SendDebug(__FUNCTION__, 'Parameter $ResetAttribute = ' . json_encode($ResetAttribute), 0);
        $this->SetWeeklyReportTimer();
        // Monitoring must be activated
        if (!$this->GetValue('Monitoring')) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Die Überwachung ist deaktiviert!', 0);
            return;
        }
        // Weekly report must be activated
        if (!$this->ReadPropertyBoolean('WeeklyReport')) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Wochenbericht ist deaktiviert!', 0);
            return;
        }
        $weekday = date('w');
        if ($weekday == $this->ReadPropertyInteger('WeeklyReportDay') || !$CheckDay) {
            $notificationCenter = $this->ReadPropertyInteger('NotificationCenter');
            if ($notificationCenter != 0 && IPS_ObjectExists($notificationCenter)) {
                $this->SendDebug(__FUNCTION__, 'Der Wochenbericht wird erstellt, Benachrichtigungen werden versendet.', 0);
                $date = date('d.m.Y');
                $location = $this->ReadPropertyString('Location');
                $weeklyLowBatteryVariables = json_decode($this->ReadAttributeString('WeeklyLowBatteryVariables'), true);
                // All batteries are ok
                if (empty($weeklyLowBatteryVariables)) {
                    // Notification is allowed
                    if (!$this->ReadPropertyBoolean('WeeklyReportOnlyWeakBattery')) {
                        // Push notification
                        if ($this->ReadPropertyBoolean('WeeklyReportUsePushNotification')) {
                            $pushTitle = substr($location, 0, 32);
                            $pushText = "\nAlle Batterien OK!\n" . $timeStamp;
                            @BENA_SendPushNotification($notificationCenter, $pushTitle, $pushText, 4);
                        }
                        // Email notification
                        if ($this->ReadPropertyBoolean('WeeklyReportUseEmailNotification')) {
                            $emailSubject = 'Batterieüberwachung ' . $location . ', Wochenbericht vom ' . $date;
                            $emailText = $this->CreateEmailReportText(2);
                            @BENA_SendEMailNotification($notificationCenter, $emailSubject, $emailText, 4);
                        }
                        // SMS Notification
                        if ($this->ReadPropertyBoolean('WeeklyReportUseSMSNotification')) {
                            $smsText = $location . "\nAlle Batterien OK!\n" . $timeStamp;
                            @BENA_SendSMSNotification($notificationCenter, $smsText, 4);
                        }
                    }
                }
                // Battery is weak
                else {
                    // Push notification
                    if ($this->ReadPropertyBoolean('WeeklyReportUsePushNotification')) {
                        foreach ($weeklyLowBatteryVariables as $variable) {
                            $pushTitle = substr($location, 0, 32);
                            $pushText = "\nBatterie schwach!\n" . $variable['name'] . ' (ID ' . $variable['id'] . ') ' . $timeStamp;
                            @BENA_SendPushNotification($notificationCenter, $pushTitle, $pushText, 4);
                        }
                    }
                    // Email notification
                    if ($this->ReadPropertyBoolean('WeeklyReportUseEmailNotification')) {
                        $emailSubject = 'Batterieüberwachung ' . $location . ', Wochenbericht vom ' . $date;
                        $emailText = $this->CreateEmailReportText(2);
                        @BENA_SendEMailNotification($notificationCenter, $emailSubject, $emailText, 4);
                    }
                    // SMS notification
                    if ($this->ReadPropertyBoolean('WeeklyReportUseSMSNotification')) {
                        foreach ($weeklyLowBatteryVariables as $variable) {
                            $smsText = $location . "\nBatterie schwach!\n" . $variable['name'] . ' (ID ' . $variable['id'] . ') ' . $timeStamp;
                            @BENA_SendSMSNotification($notificationCenter, $smsText, 4);
                        }
                    }
                }
            }
            if ($ResetAttribute) {
                $this->ResetWeeklyReportAttribute();
            }
        }
    }

    //#################### Private

    /**
     * Creates the email report text.
     *
     * @param int $NotificationType
     * 0    = Immediate notification
     * 1    = Daily report
     * 2    = Weekly report
     *
     * @return string
     */
    private function CreateEmailReportText(int $NotificationType): string
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $this->SendDebug(__FUNCTION__, 'Parameter $NotificationType = ' . json_encode($NotificationType), 0);
        switch ($NotificationType) {
            // Immediate notification
            case 0:
                $lowBatteryVariables = json_decode($this->ReadAttributeString('LowBatteryVariable'), true);
                break;

            // Daily report
            case 1:
                $lowBatteryVariables = json_decode($this->ReadAttributeString('DailyLowBatteryVariables'), true);
                break;

            // Weekly report
            case 2:
                $lowBatteryVariables = json_decode($this->ReadAttributeString('WeeklyLowBatteryVariables'), true);
                break;

        }
        $text = "Aktueller Batteriestatus:\n\n" . GetValueFormatted($this->GetIDForIdent('Status')) . "\n\n\n\n";
        if (!empty($lowBatteryVariables)) {
            $text .= "Batterie schwach:\n\n";
            // Sort variables by name
            usort($lowBatteryVariables, function ($a, $b)
            {
                return $a['name'] <=> $b['name'];
            });
            // Rebase array
            $lowBatteryVariables = array_values($lowBatteryVariables);
            foreach ($lowBatteryVariables as $variable) {
                $text .= $logText = $variable['timestamp'] . ',  ID: ' . $variable['id'] . ',  ' . $variable['name'] . ',  Adresse: ' . $variable['address'] . "\n";
            }
            $text .= "\n\n\n\n";
        }
        $monitoredVariables = json_decode($this->ReadPropertyString('MonitoredVariables'), true);
        if (!empty($monitoredVariables)) {
            // Sort variables by name
            usort($monitoredVariables, function ($a, $b)
            {
                return $a['Name'] <=> $b['Name'];
            });
            // Rebase array
            $monitoredVariables = array_values($monitoredVariables);
            $text .= "Batterie OK:\n\n";
            $timeStamp = date('d.m.Y, H:i:s');
            foreach ($monitoredVariables as $variable) {
                $id = $variable['ID'];
                if (IPS_ObjectExists($id) && $variable['Use']) {
                    $actualValue = boolval(GetValue($id));
                    $alertingValue = boolval($variable['AlertingValue']);
                    if ($actualValue != $alertingValue) {
                        $text .= $timeStamp . ',  ID: ' . $id . ',  ' . $variable['Name'] . ',  Adresse: ' . $variable['Address'] . "\n";
                    }
                }
            }
        }
        return $text;
    }
}