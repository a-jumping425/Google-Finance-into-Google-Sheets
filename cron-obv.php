<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once ('config.php');

// if (php_sapi_name() != 'cli') {
//     throw new Exception('try running "php app.php" from command line.');
// }

// Create Google API Client
$client = new Google_Client();
$client->setApplicationName(APPLICATION_NAME);
$client->setScopes(SCOPES);
$client->setAuthConfig(CLIENT_SECRET_PATH);
$client->setAccessToken(ACCESS_TOKEN);

$service = new Google_Service_Sheets($client);

class GoogleSheet_Cron {
    var $service = null;
    var $csv_data = [];
    var $symbols = null;

    function __construct($service)
    {
        global $_symbols;

        $this->service = $service;
        $this->symbols = $_symbols;
    }

    function insertBlankRowToSpreadsheet($work_sheet_id) {
        $range = new Google_Service_Sheets_DimensionRange();
        $range->setSheetId($work_sheet_id);
        $range->setDimension('ROWS');
        $range->setStartIndex(1);
        $range->setEndIndex(2);

        $insertDimension = new Google_Service_Sheets_InsertDimensionRequest();
        $insertDimension->setInheritFromBefore(false);
        $insertDimension->setRange($range);

        // Set the request
        $request = new Google_Service_Sheets_Request();
        $request->setInsertDimension($insertDimension);

        // Add the request to the requests array
        $requests = array();
        $requests[] = $request;

        // Prepare the update
        $batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest(array(
            'requests' => $requests
        ));

        try {
            // Execute the request
            $response = $this->service->spreadsheets->batchUpdate(SHEET_ID, $batchUpdateRequest);
            if ($response->valid()) {
                return true;    // Success, the row has been added
            }
        } catch (Exception $e) {
            error_log($e->getMessage());    // Something went wrong
        }

        return false;
    }

    function initSheetWithCSV() {
        $count_to_execute = 0;
        foreach ($this->symbols as $symbol_index => $symbol) {
            $code = $symbol['code'];
            $count = count($this->csv_data[$code]);

            // Get the count from start date
            if ($symbol_index == 0) {
                for ($x = 0; $x < $count; $x++) {
                    // Skip date if earlier than start date.
                    if (INIT_SHEET_START_DATE > strtotime($this->csv_data[$code][$x][0]))
                        break;
                    $count_to_execute++;
                }
            }
            $count = $count_to_execute;

            $this->csv_data[$code][$count-1]['obv']         = $symbol['base_obv'];
            $this->csv_data[$code][$count-1]['obv20ma']     = $symbol['base_obv20ma'];
            $this->csv_data[$code][$count-1]['obv3ma']      = $symbol['base_obv3ma'];

            for ($i = $count - 2; $i >= 0; $i--) {  // Except most last day
                $csv = $this->csv_data[$code][$i];

                $yesterday_price = $this->csv_data[$code][$i+1][4] * 1;
                $yesterday_obv = $this->csv_data[$code][$i+1]['obv'] * 1;

                $today_price = $csv[4] * 1;
                $today_volume = $csv[5] * 1;
                $today_obv = 0;

                // Calc today OBV
                if ($today_price > $yesterday_price) {
                    $today_obv = $yesterday_obv + $today_volume;
                } else if ($today_price < $yesterday_price) {
                    $today_obv = $yesterday_obv - $today_volume;
                } else {
                    $today_obv = $yesterday_obv;
                }
                $this->csv_data[$code][$i]['obv'] = $today_obv;

                // Calc total OBV for latest 20 rows from this day
                $loop_count = $loop_count_3ma = $total_obv20ma = $total_obv3ma = 0;
                for ($j = $i; $j < $count; $j++) {
                    if ($loop_count >= 20)
                        break;

                    $loop_count++;

                    if ($loop_count <= 3) {
                        $total_obv3ma += $this->csv_data[$code][$j]['obv'] * 1;
                        $loop_count_3ma++;
                    }

                    $total_obv20ma += $this->csv_data[$code][$j]['obv'] * 1;
                }

                // Calc today OBV 20 MA
                if ($loop_count == 20) {
                    $today_obv20ma = $total_obv20ma / $loop_count;
                    $this->csv_data[$code][$i]['obv20ma'] = $today_obv20ma;
                } else {
                    $this->csv_data[$code][$i]['obv20ma'] = $today_obv;
                }

                // Calc today OBV 3 MA
                if ($loop_count_3ma == 3) {
                    $today_obv3ma = $total_obv3ma / $loop_count_3ma;
                    $this->csv_data[$code][$i]['obv3ma'] = $today_obv3ma;
                } else {
                    $this->csv_data[$code][$i]['obv3ma'] = $today_obv;
                }
            }

            $sheet_data = array();
            for ($i = 0; $i < $count; $i++) {
                $sheet_data[$i] = [
                    $this->csv_data[$code][$i][0],
                    $this->csv_data[$code][$i][4] * 1,
                    $this->csv_data[$code][$i][5] * 1,
                    $this->csv_data[$code][$i]['obv'],
                    $this->csv_data[$code][$i]['obv20ma'],
                    $this->csv_data[$code][$i]['obv3ma']
                ];
            }

            $options = array('valueInputOption' => 'RAW');
            $body = new Google_Service_Sheets_ValueRange(['values' => $sheet_data]);

            $range = $code .'!A2:F'. ($count+1);
            $result = $this->service->spreadsheets_values->update(SHEET_ID, $range, $body, $options);
            print($result->updatedRange . PHP_EOL);
        }
    }

    function insertTodayRow() {
        foreach ($this->symbols as $symbol) {
            $code = $symbol['code'];

            // Read 20 rows from spreadsheet
            $range = $code .'!A2:F21';
            $response = $this->service->spreadsheets_values->get(SHEET_ID, $range);
            $values = $response->getValues();
            $count = count($values);

            // Check exist today row
            if ($values[0][0] == $this->csv_data[$code][0][0])
                continue;

            // Today data
            $total_20 = $total_3 = 0;
            for ($i = 0; $i < $count-1; $i++) {
                $total_20 += $values[$i][3] * 1;
                if ($i < 2)
                    $total_3 += $values[$i][3] * 1;
            }

            // Insert today row
            if ($this->insertBlankRowToSpreadsheet($symbol['work_sheet_id'])) {
                $yesterday_price = $this->csv_data[$code][1][4] * 1;
                $yesterday_obv = $values[0][3] * 1;

                $today_price = $this->csv_data[$code][0][4] * 1;
                $today_volume = $this->csv_data[$code][0][5] * 1;
                $today_obv = 0;

                // Calc today OBV
                if ($today_price > $yesterday_price) {
                    $today_obv = $yesterday_obv + $today_volume;
                } else if ($today_price < $yesterday_price) {
                    $today_obv = $yesterday_obv - $today_volume;
                } else {
                    $today_obv = $yesterday_obv;
                }

                $sheet_data[0] = [
                    $this->csv_data[$code][0][0],
                    $today_price,
                    $today_volume,
                    $today_obv,
                    ($total_20 + $today_obv) / 20,
                    ($total_3 + $today_obv) / 3
                ];

                $options = array('valueInputOption' => 'RAW');
                $body = new Google_Service_Sheets_ValueRange(['values' => $sheet_data]);

                $range = $code .'!A2:F2';
                $result = $this->service->spreadsheets_values->update(SHEET_ID, $range, $body, $options);
                print($result->updatedRange. PHP_EOL);
            }
        }
    }

    function parseGoogleFinanceCSV() {
        foreach ($this->symbols as $symbol) {
            $url = "http://www.google.com/finance/historical?q=". $symbol['download'] ."&output=csv";
            $csv_data = file_get_contents($url);
            $lines = explode("\n", $csv_data);
            $csv_data = array();
            $i = 0;
            foreach ($lines as $line) {
                $i++;
                if ($i == 1) continue;
                $line = str_getcsv($line, ',');
                if ($line[0])
                    $csv_data[] = $line;
            }
            $this->csv_data[$symbol['code']] = $csv_data;
        }
    }

    function cron_job() {
        set_time_limit(1800);

        $this->parseGoogleFinanceCSV();
        $this->initSheetWithCSV();  // Init sheet with CSV data
        // $this->insertTodayRow();
    }
}

$sheetClass = new GoogleSheet_Cron($service);
$sheetClass->cron_job();
