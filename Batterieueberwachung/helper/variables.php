<?php

// Declare
declare(strict_types=1);

trait BAT_variables
{
    /**
     * Determines the variables automatically.
     */
    public function DetermineVariables(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $listedVariables = [];
        $instanceIDs = @IPS_GetInstanceListByModuleID(self::HOMEMATIC_DEVICE_GUID);
        $date = '{"year":0, "month":0, "day":0}';
        if (!empty($instanceIDs)) {
            $variables = [];
            foreach ($instanceIDs as $instanceID) {
                $childrenIDs = @IPS_GetChildrenIDs($instanceID);
                foreach ($childrenIDs as $childrenID) {
                    $match = false;
                    $object = @IPS_GetObject($childrenID);
                    if ($object['ObjectIdent'] == 'LOWBAT' || $object['ObjectIdent'] == 'LOW_BAT') {
                        $match = true;
                    }
                    if ($match) {
                        // Check for variable
                        if ($object['ObjectType'] == 2) {
                            $name = strstr(@IPS_GetName($instanceID), ':', true);
                            if ($name == false) {
                                $name = @IPS_GetName($instanceID);
                            }
                            $deviceAddress = @IPS_GetProperty(IPS_GetParent($childrenID), 'Address');
                            array_push($variables, ['Use' => true, 'ID' => $childrenID, 'Name' => $name, 'Address' => $deviceAddress, 'AlertingValue' => 1, 'LastBatteryReplacementDate' => $date]);
                        }
                    }
                }
            }
            // Get already listed variables
            $listedVariables = json_decode($this->ReadPropertyString('MonitoredVariables'), true);
            // Add new variables
            if (!empty($listedVariables)) {
                $addVariables = array_diff(array_column($variables, 'ID'), array_column($listedVariables, 'ID'));
                if (!empty($addVariables)) {
                    foreach ($addVariables as $addVariable) {
                        $name = strstr(@IPS_GetName(@IPS_GetParent($addVariable)), ':', true);
                        $deviceAddress = @IPS_GetProperty(@IPS_GetParent($addVariable), 'Address');
                        array_push($listedVariables, ['Use' => true, 'ID' => $addVariable, 'Name' => $name, 'Address' => $deviceAddress, 'AlertingValue' => 1, 'LastBatteryReplacementDate' => $date]);
                    }
                }
            } else {
                $listedVariables = $variables;
            }
        }
        // Rebase array
        $listedVariables = array_values($listedVariables);
        // Update variable list
        IPS_SetProperty($this->InstanceID, 'MonitoredVariables', json_encode($listedVariables));
        if (IPS_HasChanges($this->InstanceID)) {
            IPS_ApplyChanges($this->InstanceID);
        }
        echo 'Die Variablen wurden automatisch ermittelt!';
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
                        $profileName = 'BATT.Battery.Integer';
                        break;

                    default:
                        // Boolean
                        $profileName = 'BATT.Battery.Boolean';
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
     */
    public function CreateVariableLinks(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        if ($this->ReadPropertyBoolean('CreateLinks')) {
            $categoryID = $this->ReadPropertyInteger('LinkCategory');
            // Define icon first
            $icon = 'Battery';
            // Get all monitored variables
            $monitoredVariables = json_decode($this->ReadPropertyString('MonitoredVariables'));
            $targetIDs = [];
            $i = 0;
            foreach ($monitoredVariables as $variable) {
                if ($variable->Use) {
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
                    if ($linkInfo == 'BATT.' . $this->InstanceID) {
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
                    @IPS_SetParent($linkID, $categoryID);
                    $position = array_search($targetID, array_column($targetIDs, 'targetID'));
                    @IPS_SetPosition($linkID, $position + 1);
                    $name = $targetIDs[$position]['name'];
                    @IPS_SetName($linkID, $name);
                    @IPS_SetLinkTargetID($linkID, $targetID);
                    @IPS_SetInfo($linkID, 'BATT.' . $this->InstanceID);
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
                    @IPS_SetInfo($linkID, 'BATT.' . $this->InstanceID);
                    @IPS_SetIcon($linkID, $icon);
                }
            }
            echo 'Die Variablenverknüpfungen wurden erfolgreich erstellt!';
        }
    }

    /**
     * Updates the battery replacement date
     *
     * @param string $VariableID
     */
    public function UpdateBatteryReplacementDate(string $VariableID): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
        $this->SendDebug(__FUNCTION__, 'Parameter $VariableID = ' . $VariableID, 0);
        $data = [];
        $monitoredVariables = json_decode($this->ReadPropertyString('MonitoredVariables'));
        if (empty($monitoredVariables)) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Es werden keine Variablen überwacht!', 0);
            return;
        }
        foreach ($monitoredVariables as $index => $variable) {
            $id = $variable->ID;
            if ($id == 0 || !IPS_ObjectExists($id)) {
                continue;
            }
            $data[$index]['Use'] = $variable->Use;
            $data[$index]['ID'] = $id;
            $data[$index]['Name'] = $variable->Name;
            $data[$index]['Address'] = $variable->Address;
            $data[$index]['AlertingValue'] = $variable->AlertingValue;
            if ($id == $VariableID) {
                $year = date('Y');
                $month = date('n');
                $day = date('j');
                $data[$index]['LastBatteryReplacementDate'] = '{"year":' . $year . ',"month":' . $month . ',"day":' . $day . '}';
            } else {
                $data[$index]['LastBatteryReplacementDate'] = $variable->LastBatteryReplacementDate;
            }
        }
        $this->SendDebug(__FUNCTION__, 'Data: ' . json_encode($data), 0);
        IPS_SetProperty($this->InstanceID, 'MonitoredVariables', json_encode($data));
        if (IPS_HasChanges($this->InstanceID)) {
            IPS_ApplyChanges($this->InstanceID);
        }
    }

    //#################### Private

    /**
     * Updates the battery list.
     */
    private function UpdateBatteryList(): void
    {
        $string = '';
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        if ($this->ReadPropertyBoolean('EnableBatteryList')) {
            $string = "<table style='width: 100%; border-collapse: collapse;'>";
            $string .= '<tr><td><b>ID</b></td><td><b>Name</b></td><td><b>Batteriestatus</b></td><td><b>Adresse</b></td><td><b>Letzter Batteriewechsel</b></td></tr>';
            $monitoredVariables = json_decode($this->ReadPropertyString('MonitoredVariables'), true);
            if (!empty($monitoredVariables)) {
                // Sort variables by name
                usort($monitoredVariables, function ($a, $b)
                {
                    return $a['Name'] <=> $b['Name'];
                });
                // Rebase array
                $monitoredVariables = array_values($monitoredVariables);
                // Low battery on top
                foreach ($monitoredVariables as $variable) {
                    if ($variable['Use']) {
                        $id = $variable['ID'];
                        if (@IPS_ObjectExists($id)) {
                            $actualValue = boolval(GetValue($id));
                            $alertingValue = boolval($variable['AlertingValue']);
                            if ($actualValue == $alertingValue) {
                                // Address
                                $address = $variable['Address'];
                                if (empty($address)) {
                                    $address = '-';
                                }
                                // Last battery replacement date
                                $date = json_decode($variable['LastBatteryReplacementDate']);
                                $year = $date->year;
                                $month = $date->month;
                                $day = $date->day;
                                if ($year == 0 && $month == 0 && $day == 0) {
                                    $lastBatteryReplacementDate = '-';
                                } else {
                                    $lastBatteryReplacementDate = $day . '.' . $month . '.' . $year;
                                }
                                // Set color to red
                                $string .= '<tr><td><span style="color:#FF0000"><b>' . $id . '</b></span></td><td><span style="color:#FF0000"><b>' . $variable['Name'] . '</b></span></td><td><span style="color:#FF0000"><b>Batterie schwach!</b></span></td><td><b><span style="color:#FF0000"><b>' . $address . '</b></td><td><span style="color:#FF0000"><b>' . $lastBatteryReplacementDate . '</b></span></td></tr>';
                            }
                        }
                    }
                }
                // Battery OK
                foreach ($monitoredVariables as $variable) {
                    if ($variable['Use']) {
                        $id = $variable['ID'];
                        if (@IPS_ObjectExists($id)) {
                            $actualValue = boolval(GetValue($id));
                            $alertingValue = boolval($variable['AlertingValue']);
                            if ($actualValue != $alertingValue) {
                                // Address
                                $address = $variable['Address'];
                                if (empty($address)) {
                                    $address = '-';
                                }
                                // Last battery replacement date
                                $date = json_decode($variable['LastBatteryReplacementDate']);
                                $year = $date->year;
                                $month = $date->month;
                                $day = $date->day;
                                if ($year == 0 && $month == 0 && $day == 0) {
                                    $lastBatteryReplacementDate = '-';
                                } else {
                                    $lastBatteryReplacementDate = $day . '.' . $month . '.' . $year;
                                }
                                $string .= '<tr><td>' . $id . '</td><td>' . $variable['Name'] . '</td><td>OK</td><td>' . $address . '</td><td>' . $lastBatteryReplacementDate . '</td></tr>';
                            }
                        }
                    }
                }
                $string .= '</table>';
            }
        }
        $this->SetValue('BatteryList', $string);
    }

    /**
     * Checks the actual status.
     */
    private function CheckActualStatus(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $state = false;
        $actualState = $this->GetValue('Status');
        $monitoredVariables = json_decode($this->ReadPropertyString('MonitoredVariables'));
        if (!empty($monitoredVariables)) {
            foreach ($monitoredVariables as $variable) {
                $id = $variable->ID;
                if (IPS_ObjectExists($id) && $variable->Use) {
                    $actualValue = boolval(GetValue($id));
                    $alertingValue = boolval($variable->AlertingValue);
                    if ($actualValue == $alertingValue) {
                        $state = true;
                    }
                }
            }
        }
        $this->SetValue('Status', $state);
        // Execute script if the status has changed
        if ($state != $actualState) {
            $id = $this->ReadPropertyInteger('NotificationScript');
            if ($id != 0 && IPS_ObjectExists($id)) {
                IPS_RunScriptEx($id, ['MonitoringStatus' => $actualState]);
            }
        }
    }

    /**
     * Executes the alerting.
     *
     * @param int $SenderID
     * @param bool $ActualValue
     */
    private function TriggerAlerting(int $SenderID, bool $ActualValue): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $this->SendDebug(__FUNCTION__, 'Parameter $SenderID = ' . $SenderID, 0);
        $this->SendDebug(__FUNCTION__, 'Parameter $ActualValue = ' . json_encode($ActualValue), 0);
        // Variables must exist
        $monitoredVariables = json_decode($this->ReadPropertyString('MonitoredVariables'), true);
        if (empty($monitoredVariables)) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, Es werden keine Variablen überwacht!', 0);
            return;
        }
        $this->TriggerImmediateNotification($SenderID, $ActualValue);
    }
}