<?php

// Declare
declare(strict_types=1);

trait BAT_variables
{
    /**
     * Checks the overall status for low battery and update overdue.
     *
     * @param int $Mode
     * 0    = immediate notification
     * 1    = daily notification
     * 2    = weekly notification
     */
    public function CheckMonitoredVariables(int $Mode): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $actualOverallStatus = false;
        // Check for existing variables
        if (!$this->CheckForExistingVariables()) {
            // Reset value
            $this->SetValue('Status', $actualOverallStatus);
            // Clear battery list
            $this->SetValue('BatteryList', '');
            $this->SendDebug(__FUNCTION__, 'Abbruch, es werden keine Variablen überwacht!', 0);
            // Reset critical state variables
            $this->WriteAttributeString('CriticalStateVariables', '{"immediateNotification":[],"dailyNotification":[],"weeklyNotification":[]}');
            return;
        }
        $timeStamp = date('d.m.Y, H:i:s');
        $batteryList = [];
        // Check variables
        $monitoredVariables = json_decode($this->ReadPropertyString('MonitoredVariables'), true);
        if (!empty($monitoredVariables)) {
            foreach ($monitoredVariables as $variable) {
                $id = $variable['ID'];
                $name = $variable['Name'];
                $comment = $variable['Comment'];
                if ($id != 0 && IPS_ObjectExists($id)) {
                    /*
                     * 0    = normal
                     * 1    = low battery
                     * 2    = update overdue
                     */
                    $actualStatus = 0;
                    $unicode = json_decode('"\u2705"'); // white_check_mark
                    // Check for low battery
                    if ($variable['CheckBattery']) {
                        $actualValue = boolval(GetValue($id));
                        $alertingValue = boolval($variable['AlertingValue']);
                        if ($actualValue == $alertingValue) {
                            $actualStatus = 1;
                            $unicode = json_decode('"\u26a0\ufe0f"'); // warning
                            $actualOverallStatus = true;
                        }
                    }
                    // Check for update overdue
                    if ($variable['CheckUpdate']) {
                        $now = time();
                        $variableUpdate = IPS_GetVariable($id)['VariableUpdated'];
                        $dateDifference = ($now - $variableUpdate) / (60 * 60 * 24);
                        if ($dateDifference > $variable['UpdatePeriod']) {
                            $actualStatus = 2;
                            $unicode = json_decode('"\u2757"'); // heavy_exclamation_mark
                            $actualOverallStatus = true;
                        }
                    }
                    // Last battery replacement
                    $lastBatteryReplacement = 'Nie';
                    $replacementDate = json_decode($variable['LastBatteryReplacement']);
                    $lastBatteryReplacementYear = $replacementDate->year;
                    $lastBatteryReplacementMonth = $replacementDate->month;
                    $lastBatteryReplacementDay = $replacementDate->day;
                    if ($lastBatteryReplacementYear != 0 && $lastBatteryReplacementMonth != 0 && $lastBatteryReplacementDay != 0) {
                        $lastBatteryReplacement = $lastBatteryReplacementDay . '.' . $lastBatteryReplacementMonth . '.' . $lastBatteryReplacementYear;
                    }
                    // Update battery list
                    array_push($batteryList, [
                        'ActualStatus'           => $actualStatus,
                        'Unicode'                => $unicode,
                        'ID'                     => $id,
                        'Name'                   => $name,
                        'Comment'                => $comment,
                        'LastBatteryReplacement' => $lastBatteryReplacement]);

                    // Update critical state variables
                    $criticalStateVariables = json_decode($this->ReadAttributeString('CriticalStateVariables'), true);
                    // Check if variable already exists
                    $key = array_search($id, array_column($criticalStateVariables['immediateNotification'], 'id'));
                    // Variable already exists, update actual status and timestamp
                    if (is_int($key)) {
                        $criticalStateVariables['immediateNotification'][$key]['actualStatus'] = $actualStatus;
                        $criticalStateVariables['immediateNotification'][$key]['timestamp'] = $timeStamp;
                    } // Variable doesn't exist, add variable to list
                    else {
                        array_push($criticalStateVariables['immediateNotification'], ['actualStatus' => $actualStatus, 'id' => $id, 'name' => $name, 'comment' => $comment, 'timestamp' => $timeStamp]);
                    }
                    // Check if variable already exists
                    $key = array_search($id, array_column($criticalStateVariables['dailyNotification'], 'id'));
                    // Variable already exists, update actual status and timestamp
                    if (is_int($key)) {
                        if ($actualStatus != 0) {
                            $criticalStateVariables['dailyNotification'][$key]['actualStatus'] = $actualStatus;
                            $criticalStateVariables['dailyNotification'][$key]['timestamp'] = $timeStamp;
                        }
                    } // Variable doesn't exist, add variable to list
                    else {
                        array_push($criticalStateVariables['dailyNotification'], ['actualStatus' => $actualStatus, 'id' => $id, 'name' => $name, 'comment' => $comment, 'timestamp' => $timeStamp]);
                    }
                    // Check if variable already exists
                    $key = array_search($id, array_column($criticalStateVariables['weeklyNotification'], 'id'));
                    // Variable already exists, update actual status and timestamp
                    if (is_int($key)) {
                        if ($actualStatus != 0) {
                            $criticalStateVariables['weeklyNotification'][$key]['actualStatus'] = $actualStatus;
                            $criticalStateVariables['weeklyNotification'][$key]['timestamp'] = $timeStamp;
                        }
                    } // Variable doesn't exist, add variable to list
                    else {
                        array_push($criticalStateVariables['weeklyNotification'], ['actualStatus' => $actualStatus, 'id' => $id, 'name' => $name, 'comment' => $comment, 'timestamp' => $timeStamp]);
                    }
                    $this->WriteAttributeString('CriticalStateVariables', json_encode($criticalStateVariables));
                }
            }
            // Battery List for WebFront
            $string = '';
            if ($this->ReadPropertyBoolean('EnableBatteryList')) {
                $string = "<table style='width: 100%; border-collapse: collapse;'>";
                $string .= '<tr><td><b>Status</b></td><td><b>ID</b></td><td><b>Name</b></td><td><b>Adresse</b></td><td><b>Letzter Batteriewechsel</b></td></tr>';
                // Sort variables by name
                usort($batteryList, function ($a, $b)
                {
                    return $a['Name'] <=> $b['Name'];
                });
                // Rebase array
                $batteryList = array_values($batteryList);
                if (!empty($batteryList)) {
                    // Show update overdue first
                    foreach ($batteryList as $battery) {
                        $id = $battery['ID'];
                        if ($id != 0 && IPS_ObjectExists($id)) {
                            if ($battery['ActualStatus'] == 2) {
                                $string .= '<tr><td>' . $battery['Unicode'] . '</td><td>' . $id . '</td><td>' . $battery['Name'] . '</td><td>' . $battery['Comment'] . '</td><td>' . $battery['LastBatteryReplacement'] . '</td></tr>';
                            }
                        }
                    }
                    // Low battery is next
                    foreach ($batteryList as $battery) {
                        $id = $battery['ID'];
                        if ($id != 0 && IPS_ObjectExists($id)) {
                            if ($battery['ActualStatus'] == 1) {
                                $string .= '<tr><td>' . $battery['Unicode'] . '</td><td>' . $id . '</td><td>' . $battery['Name'] . '</td><td>' . $battery['Comment'] . '</td><td>' . $battery['LastBatteryReplacement'] . '</td></tr>';
                            }
                        }
                    }
                    // Normal status is last
                    foreach ($batteryList as $battery) {
                        $id = $battery['ID'];
                        if ($id != 0 && IPS_ObjectExists($id)) {
                            if ($battery['ActualStatus'] == 0) {
                                $string .= '<tr><td>' . $battery['Unicode'] . '</td><td>' . $id . '</td><td>' . $battery['Name'] . '</td><td>' . $battery['Comment'] . '</td><td>' . $battery['LastBatteryReplacement'] . '</td></tr>';
                            }
                        }
                    }
                }
                $string .= '</table>';
            }
            $this->SetValue('BatteryList', $string);
            // Set status
            $lastOverallStatus = $this->GetValue('Status');
            $this->SetValue('Status', $actualOverallStatus);
            if ($this->GetValue('Monitoring')) {
                if ($actualOverallStatus != $lastOverallStatus) {
                    if ($Mode == 0) {
                        $this->TriggerImmediateNotification();
                    }
                    // Notification script
                    $id = $this->ReadPropertyInteger('NotificationScript');
                    if ($id != 0 && IPS_ObjectExists($id)) {
                        IPS_RunScriptEx($id, ['MonitoringStatus' => $actualOverallStatus]);
                    }
                }
            }
        }
    }

    /**
     * Determines the Homematic variables automatically.
     */
    public function DetermineHomematicVariables(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $monitoredVariables = [];
        $instances = @IPS_GetInstanceListByModuleID(self::HOMEMATIC_DEVICE_GUID);
        if (!empty($instances)) {
            $variables = [];
            foreach ($instances as $instance) {
                $children = @IPS_GetChildrenIDs($instance);
                foreach ($children as $child) {
                    $match = false;
                    $object = @IPS_GetObject($child);
                    if ($object['ObjectIdent'] == 'LOWBAT' || $object['ObjectIdent'] == 'LOW_BAT') {
                        $match = true;
                    }
                    if ($match) {
                        // Check for variable
                        if ($object['ObjectType'] == 2) {
                            array_push($variables, ['ID' => $child]);
                        }
                    }
                }
            }
            // Get already listed variables
            $monitoredVariables = json_decode($this->ReadPropertyString('MonitoredVariables'), true);
            // Add new variables
            $newVariables = array_diff(array_column($variables, 'ID'), array_column($monitoredVariables, 'ID'));
            if (!empty($newVariables)) {
                foreach ($newVariables as $variable) {
                    $name = strstr(@IPS_GetName(@IPS_GetParent($variable)), ':', true);
                    $address = @IPS_GetProperty(@IPS_GetParent($variable), 'Address');
                    $lastBatteryReplacement = '{"year":0, "month":0, "day":0}';
                    array_push($monitoredVariables, [
                        'ID'                     => $variable,
                        'Name'                   => $name,
                        'Comment'                => $address,
                        'CheckBattery'           => true,
                        'AlertingValue'          => 1,
                        'CheckUpdate'            => true,
                        'UpdatePeriod'           => 3,
                        'LastBatteryReplacement' => $lastBatteryReplacement]);
                }
            }
        }
        // Sort variables by name
        usort($monitoredVariables, function ($a, $b)
        {
            return $a['Name'] <=> $b['Name'];
        });
        // Rebase array
        $monitoredVariables = array_values($monitoredVariables);
        // Update variable list
        IPS_SetProperty($this->InstanceID, 'MonitoredVariables', json_encode($monitoredVariables));
        if (IPS_HasChanges($this->InstanceID)) {
            IPS_ApplyChanges($this->InstanceID);
        }
        $this->ReloadConfiguration();
        echo 'Die Homematic Variablen wurden automatisch ermittelt!';
    }

    /**
     * Assigns the profile to the variable.
     *
     * @param bool $Override
     * false    = our profile will only be assigned, if the variables has no existing profile.
     * true     = our profile will be assigned to the variables.
     */
    public function AssignVariableProfile(bool $Override): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $this->SendDebug(__FUNCTION__, 'Parameter $Override = ' . json_encode($Override), 0);
        // Assign profile only for listed variables
        $monitoredVariables = json_decode($this->ReadPropertyString('MonitoredVariables'));
        if (!empty($monitoredVariables)) {
            foreach ($monitoredVariables as $variable) {
                $variableType = @IPS_GetVariable($variable->ID)['VariableType'];
                $profileName = null;
                switch ($variableType) {
                    case 1:
                        // Integer
                        $profileName = 'BAT.Battery.Integer';
                        break;

                    default:
                        // Boolean
                        $profileName = 'BAT.Battery.Boolean';
                }
                // Always assign profile
                if ($Override) {
                    if (!is_null($profileName)) {
                        @IPS_SetVariableCustomProfile($variable->ID, $profileName);
                    }
                } // Only assign profile, if variable has no profile
                else {
                    // Check if variable has a profile
                    $assignedProfile = @IPS_GetVariable($variable->ID)['VariableProfile'];
                    if (empty($assignedProfile)) {
                        @IPS_SetVariableCustomProfile($variable->ID, $profileName);
                    }
                }
            }
        }
        echo 'Die Variablenprofile wurden zugewiesen!';
    }

    /**
     * Creates links of monitored variables.
     *
     * @param int $LinkCategory
     */
    public function CreateVariableLinks(int $LinkCategory): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $icon = 'Battery';
        // Get all monitored variables
        $monitoredVariables = json_decode($this->ReadPropertyString('MonitoredVariables'));
        $targetIDs = [];
        $i = 0;
        foreach ($monitoredVariables as $variable) {
            if ($variable->CheckBattery || $variable->CheckUpdate) {
                $targetIDs[$i] = ['name' => $variable->Name, 'targetID' => $variable->ID];
                $i++;
            }
        }
        // Sort array alphabetically by device name
        sort($targetIDs);
        // Get all existing links (links have not an ident field, so we use the object info field)
        $existingTargetIDs = [];
        $links = @IPS_GetLinkList();
        if (!empty($links)) {
            $i = 0;
            foreach ($links as $link) {
                $linkInfo = @IPS_GetObject($link)['ObjectInfo'];
                if ($linkInfo == 'BAT.' . $this->InstanceID) {
                    // Get target id
                    $existingTargetID = @IPS_GetLink($link)['TargetID'];
                    $existingTargetIDs[$i] = ['linkID' => $link, 'targetID' => $existingTargetID];
                    $i++;
                }
            }
        }
        // Delete dead links
        $deadLinks = array_diff(array_column($existingTargetIDs, 'targetID'), array_column($targetIDs, 'targetID'));
        if (!empty($deadLinks)) {
            foreach ($deadLinks as $targetID) {
                $position = array_search($targetID, array_column($existingTargetIDs, 'targetID'));
                $linkID = $existingTargetIDs[$position]['linkID'];
                if (@IPS_LinkExists($linkID)) {
                    @IPS_DeleteLink($linkID);
                }
            }
        }
        // Create new links
        $newLinks = array_diff(array_column($targetIDs, 'targetID'), array_column($existingTargetIDs, 'targetID'));
        if (!empty($newLinks)) {
            foreach ($newLinks as $targetID) {
                $linkID = @IPS_CreateLink();
                @IPS_SetParent($linkID, $LinkCategory);
                $position = array_search($targetID, array_column($targetIDs, 'targetID'));
                @IPS_SetPosition($linkID, $position + 1);
                $name = $targetIDs[$position]['name'];
                @IPS_SetName($linkID, $name);
                @IPS_SetLinkTargetID($linkID, $targetID);
                @IPS_SetInfo($linkID, 'BAT.' . $this->InstanceID);
                @IPS_SetIcon($linkID, $icon);
            }
        }
        // Edit existing links
        $existingLinks = array_intersect(array_column($existingTargetIDs, 'targetID'), array_column($targetIDs, 'targetID'));
        if (!empty($existingLinks)) {
            foreach ($existingLinks as $targetID) {
                $position = array_search($targetID, array_column($targetIDs, 'targetID'));
                $targetID = $targetIDs[$position]['targetID'];
                $index = array_search($targetID, array_column($existingTargetIDs, 'targetID'));
                $linkID = $existingTargetIDs[$index]['linkID'];
                @IPS_SetPosition($linkID, $position + 3);
                $name = $targetIDs[$position]['name'];
                @IPS_SetName($linkID, $name);
                @IPS_SetInfo($linkID, 'BAT.' . $this->InstanceID);
                @IPS_SetIcon($linkID, $icon);
            }
        }
        echo 'Die Variablenverknüpfungen wurden erfolgreich erstellt!';
    }

    /**
     * Updates the battery replacement date of the specified variable.
     *
     * @param int $VariableID
     */
    public function UpdateBatteryReplacement(int $VariableID): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
        $this->SendDebug(__FUNCTION__, 'Parameter $VariableID = ' . $VariableID, 0);
        $data = [];
        if (!$this->CheckForExistingVariables()) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Es werden keine Variablen überwacht!', 0);
            return;
        }
        if ($VariableID == 0 || !IPS_ObjectExists($VariableID)) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Die Variable mit der ID ' . $VariableID . 'existiert nicht!', 0);
            return;
        }
        $monitoredVariables = json_decode($this->ReadPropertyString('MonitoredVariables'));
        foreach ($monitoredVariables as $index => $variable) {
            $id = $variable->ID;
            if ($id == 0 || !IPS_ObjectExists($id)) {
                continue;
            }
            $data[$index]['ID'] = $id;
            $data[$index]['Name'] = $variable->Name;
            $data[$index]['Comment'] = $variable->Comment;
            $data[$index]['CheckBattery'] = $variable->CheckBattery;
            $data[$index]['AlertingValue'] = $variable->AlertingValue;
            $data[$index]['CheckUpdate'] = $variable->CheckUpdate;
            $data[$index]['UpdatePeriod'] = $variable->UpdatePeriod;
            if ($id == $VariableID) {
                $year = date('Y');
                $month = date('n');
                $day = date('j');
                $data[$index]['LastBatteryReplacement'] = '{"year":' . $year . ',"month":' . $month . ',"day":' . $day . '}';
            } else {
                $data[$index]['LastBatteryReplacement'] = $variable->LastBatteryReplacement;
            }
        }
        $timeStamp = date('d.m.Y, H:i:s');
        $criticalStateVariables = json_decode($this->ReadAttributeString('CriticalStateVariables'), true);
        // Check if variable already exists in daily notification
        $key = array_search($VariableID, array_column($criticalStateVariables['dailyNotification'], 'id'));
        // Variable already exists, update actual status and timestamp
        if (is_int($key)) {
            $criticalStateVariables['dailyNotification'][$key]['actualStatus'] = 0; // Battery OK
            $criticalStateVariables['dailyNotification'][$key]['timestamp'] = $timeStamp;
        }
        // Check if variable already exists in weekly notification
        $key = array_search($VariableID, array_column($criticalStateVariables['weeklyNotification'], 'id'));
        // Variable already exists, update actual status and timestamp
        if (is_int($key)) {
            $criticalStateVariables['weeklyNotification'][$key]['actualStatus'] = 0; // Battery OK
            $criticalStateVariables['weeklyNotification'][$key]['timestamp'] = $timeStamp;
        }
        $this->WriteAttributeString('CriticalStateVariables', json_encode($criticalStateVariables));
        IPS_SetProperty($this->InstanceID, 'MonitoredVariables', json_encode($data));
        if (IPS_HasChanges($this->InstanceID)) {
            IPS_ApplyChanges($this->InstanceID);
        }
    }

    //#################### Private

    /**
     * Checks for existing variables for monitoring.
     *
     * @return bool
     * false    = no monitored variable exists
     * true     = monitored variables exist
     */
    private function CheckForExistingVariables(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $result = false;
        $monitoredVariables = json_decode($this->ReadPropertyString('MonitoredVariables'), true);
        if (!empty($monitoredVariables)) {
            foreach ($monitoredVariables as $variable) {
                $id = $variable['ID'];
                if ($id != 0 && IPS_ObjectExists($id)) {
                    if ($variable['CheckBattery'] || $variable['CheckUpdate']) {
                        return true;
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Cleans up the critical state varibales for non existing variables anymore.
     */
    private function CleanUpCriticalStateVariables(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        if (!$this->CheckForExistingVariables()) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, es werden keine Variablen überwacht!', 0);
            return;
        }
        $monitoredVariables = json_decode($this->ReadPropertyString('MonitoredVariables'), true);
        $criticalStateVariables = json_decode($this->ReadAttributeString('CriticalStateVariables'), true);
        // Check daily notification
        $deletedDailyNotificationVariables = array_diff(array_column($criticalStateVariables['dailyNotification'], 'id'), array_column($monitoredVariables, 'ID'));
        if (!empty($deletedDailyNotificationVariables)) {
            foreach ($deletedDailyNotificationVariables as $key => $variable) {
                unset($criticalStateVariables['dailyNotification'][$key]);
            }
        }
        $criticalStateVariables['dailyNotification'] = array_values($criticalStateVariables['dailyNotification']);
        // Check weekly notification
        $deletedWeeklyNotificationVariables = array_diff(array_column($criticalStateVariables['weeklyNotification'], 'id'), array_column($monitoredVariables, 'ID'));
        if (!empty($deletedWeeklyNotificationVariables)) {
            foreach ($deletedWeeklyNotificationVariables as $key => $variable) {
                unset($criticalStateVariables['weeklyNotification'][$key]);
            }
        }
        $criticalStateVariables['weeklyNotification'] = array_values($criticalStateVariables['weeklyNotification']);
        $this->WriteAttributeString('CriticalStateVariables', json_encode($criticalStateVariables));
    }
}