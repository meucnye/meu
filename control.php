<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$statusFile = 'status.json';

// กำหนดค่าเริ่มต้นทุกครั้งที่เปิดบอร์ด (ให้แน่ใจว่า Relay อยู่ในสถานะปิด)
exec("gpio mode 0 out");
exec("gpio mode 1 out");
exec("gpio write 0 1"); // ปิดรีเลย์
exec("gpio write 1 1"); // ปิดมอเตอร์

// ฟังก์ชันเขียนค่าไปที่ status.json
function writeStatus($data) {
    global $statusFile;
    error_log("Writing status: " . json_encode($data)); // เพิ่มการบันทึก
    file_put_contents($statusFile, json_encode($data, JSON_PRETTY_PRINT));
}

function readStatus() {
    global $statusFile;
    $initialStatus = [
        'door' => 'closed',
        'autoMode' => 'off',
        'brightness' => 50,
        'colorTemperature' => 4000,
        'ldrThreshold' => 50,
        // ลบ userLdrThreshold ออก
    ];

    if (!file_exists($statusFile)) {
        writeStatus($initialStatus);
        return $initialStatus;
    }

    $data = json_decode(file_get_contents($statusFile), true);
    return is_array($data) && isset($data['door']) ? $data : $initialStatus;
}

function getCurrentLDR() {
    $ldrValue = shell_exec("cat /sys/class/sensors/ldr_value"); // เปลี่ยนเป็นคำสั่งจริง
    return is_numeric($ldrValue) ? intval(trim($ldrValue)) : null;
}   
function updateLightThreshold($value) {
    $status = readStatus();
    if ($status['autoMode'] === 'on') {
        $status['brightness'] = $value;
        writeStatus($status);
        return true;
    }
    return false;
}

function getLightStatus() {
    $status = readStatus();
    return [
        'intensity' => $status['ldrThreshold'],
        'threshold' => $status['brightness'],
        'autoMode' => $status['autoMode']
    ];
}
function updateLightStatus($value) {
    $status = readStatus();
    $threshold = $status['brightness'];
    
    // บันทึกค่าแสงปัจจุบัน
    $status['ldrThreshold'] = $value;
    
    // แก้เงื่อนไขการเปรียบเทียบ
    if ($value <= $threshold) {  // แสงมาก = ค่าต่ำ
        $status['lastAction'] = 'high';
    } else {  // แสงน้อย = ค่าสูง
        $status['lastAction'] = 'low';
    }
    
    writeStatus($status);
    return true;
}


function getStoredBrightness() {
    $status = readStatus();
    return $status['brightness'];
}

function getLastAction() {
    $status = readStatus();
    return isset($status['lastAction']) ? $status['lastAction'] : '';
}

function setLastAction($action) {
    $status = readStatus();
    $status['lastAction'] = $action;
    writeStatus($status);
}


// โหลดค่าเริ่มต้น
$status = readStatus();
$action = isset($_GET['action']) ? $_GET['action'] : null;
$response = [];

switch ($action) {
    case 'open':
        if ($status['door'] !== 'open') {
            $status['door'] = 'open';
            writeStatus($status);
            $output1 = shell_exec("gpio write 0 0 2>&1");
            $output2 = shell_exec("gpio write 1 0 2>&1");
            $response = [
                'success' => true,
                'message' => 'Door opened.',
                'gpio_output' => [$output1, $output2]
            ];
        } else {
            $response = ['success' => false, 'message' => 'Door is already open.'];
        }
        break;

    case 'close':
        if ($status['door'] !== 'closed') {
            $status['door'] = 'closed';
            writeStatus($status);
            $output1 = shell_exec("gpio write 0 1 2>&1");
            $output2 = shell_exec("gpio write 1 1 2>&1");
            $response = [
                'success' => true,
                'message' => 'Door closed.',
                'gpio_output' => [$output1, $output2]
            ];
        } else {
            $response = ['success' => false, 'message' => 'Door is already closed.'];
        }
        break;

    case 'toggle_relay':
        $status['door'] = ($status['door'] === 'closed') ? 'open' : 'closed';
        writeStatus($status);
        $gpioState = ($status['door'] === 'open') ? 0 : 1; // Active Low
        $output1 = shell_exec("gpio write 0 " . escapeshellarg($gpioState) . " 2>&1");
        $output2 = shell_exec("gpio write 1 " . escapeshellarg($gpioState) . " 2>&1");
        $response = [
            'success' => true,
            'message' => 'Relay toggled: ' . $status['door'],
            'gpio_output' => [$output1, $output2]
        ];
        break;

        case 'auto_on':
            $status['autoMode'] = 'on';
            $status['lastAction'] = ''; // รีเซ็ตสถานะการทำงานล่าสุด
            
            // เพิ่มการตรวจสอบสถานะแสงปัจจุบันและปรับรีเลย์ทันที
            $currentLDR = getCurrentLDR();
            $threshold = $status['brightness'];
            
            if ($currentLDR !== null) {
                if ($currentLDR <= $threshold) {
                    shell_exec("gpio write 0 0"); // เปิดรีเลย์ 1 (แสงมาก)
                    shell_exec("gpio write 1 1"); // ปิดรีเลย์ 2
                    $status['lastAction'] = 'high';
                } else {
                    shell_exec("gpio write 0 1"); // ปิดรีเลย์ 1
                    shell_exec("gpio write 1 0"); // เปิดรีเลย์ 2 (แสงน้อย)
                    $status['lastAction'] = 'low';
                }
            }
            
            writeStatus($status);
            error_log("Auto mode activated. Current status: " . json_encode($status));
            $response = [
                'success' => true, 
                'message' => 'Auto mode activated and initial state set.',
                'currentLDR' => $currentLDR,
                'threshold' => $threshold,
                'initialState' => $status['lastAction']
            ];
            break;
        
    case 'auto_off':
                $status['autoMode'] = 'off';
                $status['lastState'] = ''; // รีเซ็ตสถานะ
                writeStatus($status);
                $response = ['success' => true, 'message' => 'Auto mode deactivated.'];
                break;
            
        
        

                case 'set_brightness':
                    if (isset($_GET['value']) && is_numeric($_GET['value'])) {
                        $brightness = intval($_GET['value']);
                        $status['brightness'] = $brightness;
                        writeStatus($status);
                        
                        if ($status['autoMode'] === 'on') {
                            $currentLDR = getCurrentLDR();
                            error_log("LDR Value Read: " . $currentLDR);
                            if ($currentLDR === null) {
                                $response = ['success' => false, 'message' => 'LDR sensor error'];
                                break;
                            }
                            $response = ['success' => true, 'message' => 'Threshold updated', 'ldr' => $currentLDR];
                        } else {
                            $response = ['success' => true, 'message' => 'Brightness set', 'brightness' => $brightness];
                        }
                    } else {
                        $response = ['success' => false, 'message' => 'Invalid brightness value'];
                    }
                    break;
                
            
            
            
            

    case 'set_color_temperature':
        if (isset($_GET['value']) && is_numeric($_GET['value'])) {
            $colorTemperature = intval($_GET['value']);
            $status['colorTemperature'] = $colorTemperature;
            writeStatus($status);
            $output = shell_exec("gpio write 2 " . escapeshellarg($colorTemperature > 4000 ? 0 : 1) . " 2>&1");
            $response = [
                'success' => true,
                'message' => 'Color temperature updated.',
                'colorTemperature' => $colorTemperature,
                'gpio_output' => $output
            ];
        } else {
            $response = ['success' => false, 'message' => 'Invalid color temperature value.'];
        }
        break;

        case 'set_light':
            if (isset($_GET['value'])) {
                $intensity = intval($_GET['value']);
                $threshold = $status['brightness'];
        
                if ($status['autoMode'] === 'on') {
                    if ($intensity <= $threshold && $status['lastAction'] !== 'high') {
                        $status['lastAction'] = 'high';
                        shell_exec("gpio write 0 0"); // เปิดรีเลย์ 1 (แสงมาก)
                        shell_exec("gpio write 1 1"); // ปิดรีเลย์ 2
                    } elseif ($intensity > $threshold && $status['lastAction'] !== 'low') {
                        $status['lastAction'] = 'low';
                        shell_exec("gpio write 0 1"); // ปิดรีเลย์ 1
                        shell_exec("gpio write 1 0"); // เปิดรีเลย์ 2 (แสงน้อย)
                    }
                }
        
                $status['ldrThreshold'] = $intensity;
                writeStatus($status);
        
                $response = [
                    'success' => true,
                    'message' => 'Light control updated',
                    'intensity' => $intensity,
                    'threshold' => $threshold,
                    'lastAction' => $status['lastAction']
                ];
            }
            break;
        
        
        
    
            case 'get_light_intensity':
                // ดึงค่าความเข้มของแสงจากสถานะที่บันทึกไว้
                $lightIntensity = $status['ldrThreshold']; // ใช้ค่า ldrThreshold ที่ถูกตั้งค่าใน set_light
                $response = [
                    'success' => true,
                    'intensity' => $lightIntensity,
                    'ldrThreshold' => $status['ldrThreshold'],
                    'message' => 'ดึงค่าความเข้มของแสงสำเร็จ'
                ];
                break;
            
            
            

                case 'status':
                    $response = [
                        'success' => true,
                        'door' => $status['door'],
                        'autoMode' => $status['autoMode'],
                        'brightness' => $status['brightness'],
                        'colorTemperature' => $status['colorTemperature'],
                        'ldrThreshold' => $status['ldrThreshold']
                    ];
                    break;
                

    case 'start_system':
            $status['systemStarted'] = 'true';
            writeStatus($status);
            $response = ['success' => true, 'message' => 'System started.'];
            break;
            
        
        

    default:
        $response = ['success' => false, 'message' => 'Invalid action.'];
}

echo json_encode($response);

?>