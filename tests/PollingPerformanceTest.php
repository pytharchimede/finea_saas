<?php
declare(strict_types=1);

function pollingCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$notificationEndpoint = file_get_contents($root.'/api/notifications_poll.php');
pollingCheck(!str_contains($notificationEndpoint, 'ClientProjectCompletenessAlertService'), 'Completeness dispatch must stay out of notification polling');
pollingCheck(str_contains($notificationEndpoint, 'session_write_close()'), 'Notification polling must release the session lock');

$chatEndpoints = [
    'employee_chat_bootstrap.php', 'employee_chat_typing.php', 'employee_chat_messages.php',
    'employee_chat_group_create.php', 'employee_chat_group_members.php',
    'employee_chat_download.php', 'employee_chat_send.php',
];
foreach ($chatEndpoints as $endpoint) {
    $source = file_get_contents($root.'/api/'.$endpoint);
    pollingCheck(!str_contains($source, 'EmployeeChat::ensureTables'), $endpoint.' must not run schema checks');
    pollingCheck(str_contains($source, 'session_write_close()'), $endpoint.' must release the session lock');
}

$employeeChatJs = file_get_contents($root.'/decaissement/assets/js/app.js');
pollingCheck(str_contains($employeeChatJs, 'activePollIntervalMs: 15000'), 'Active employee chat polling must be 15 seconds');
pollingCheck(str_contains($employeeChatJs, ': 60000'), 'Inactive employee chat polling must be 60 seconds');
pollingCheck(str_contains($employeeChatJs, 'document.hidden'), 'Employee chat polling must honor page visibility');

$communicationJs = file_get_contents($root.'/js/communication.js');
pollingCheck(str_contains($communicationJs, '}, 15000);'), 'Communication messages must poll every 15 seconds');
pollingCheck(str_contains($communicationJs, '}, 7000);'), 'Typing must poll every 7 seconds');
pollingCheck(!str_contains($communicationJs, 'typingPollTimer = setInterval'), 'Legacy interval-based typing polling remains');

$completeness = file_get_contents($root.'/services/ClientProjectCompletenessAlertService.php');
pollingCheck(str_contains($completeness, 'CAST(c.id_client AS UNSIGNED)=CAST(o.client_id AS UNSIGNED)'), 'Completeness client join must avoid textual collation comparison');

echo "PollingPerformanceTest: OK\n";
