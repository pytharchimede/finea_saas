<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/NotificationController.php';

if (!function_exists('commTaskIniToBytes')) {
    function commTaskIniToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float)$value;
        switch ($unit) {
            case 'g':
                $number *= 1024;
            case 'm':
                $number *= 1024;
            case 'k':
                $number *= 1024;
                break;
        }

        return (int)round($number);
    }
}

$maxTaskUploadBytes = commTaskIniToBytes((string)ini_get('upload_max_filesize'));
$maxTaskUploadLabel = (string)ini_get('upload_max_filesize');
$communicationPushRecipients = [];
try {
    $notificationManager = new \NotificationController(\Database::getConnection());
    $communicationPushRecipients = array_values(array_filter(
        $notificationManager->listCommunicationPushRecipients(),
        static function (array $recipient): bool {
            return !empty($recipient['is_selected']);
        }
    ));
} catch (Throwable $e) {
    $communicationPushRecipients = [];
}
$pageTitle = 'Tableau des taches';
$activeMenu = 'communication';
$page = 'communication';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<style>
    .comm-shell {
        display: grid;
        grid-template-columns: 320px 1fr;
        gap: 24px;
        align-items: start
    }

    .comm-panel {
        background: #fff;
        border: 1px solid rgba(226, 232, 240, .9);
        border-radius: 28px;
        box-shadow: 0 18px 60px rgba(15, 23, 42, .07)
    }

    .comm-sidebar {
        padding: 24px;
        position: sticky;
        top: 92px;
        max-height: calc(100vh - 112px);
        overflow: hidden;
        display: flex;
        flex-direction: column
    }

    .comm-main {
        padding: 24px;
        display: grid;
        grid-template-rows: auto auto minmax(0, 1fr);
        min-height: calc(100vh - 112px)
    }

    .comm-sidebar-scroll {
        display: flex;
        flex-direction: column;
        gap: 20px;
        min-height: 0;
        overflow-y: auto;
        padding-right: 4px
    }

    .comm-accordion {
        border: 1px solid rgba(186, 230, 253, .95);
        border-radius: 24px;
        background: rgba(240, 249, 255, .78)
    }

    .comm-accordion summary {
        list-style: none;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding: 16px 18px;
        font-size: 14px;
        font-weight: 700;
        color: #0f172a
    }

    .comm-accordion summary::-webkit-details-marker {
        display: none
    }

    .comm-accordion summary::after {
        content: '+';
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 9999px;
        background: #fff;
        color: #0891b2;
        font-size: 18px;
        font-weight: 900;
        box-shadow: 0 8px 18px rgba(8, 145, 178, .08)
    }

    .comm-accordion[open] summary::after {
        content: '−'
    }

    .comm-accordion-body {
        padding: 0 18px 18px
    }

    .comm-hero-btn {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        border: 0;
        border-radius: 18px;
        padding: 12px 18px;
        font-size: 14px;
        font-weight: 800;
        color: #fff;
        background: linear-gradient(135deg, #0891b2 0%, #0f172a 100%);
        box-shadow: 0 18px 32px rgba(8, 145, 178, .24);
        transition: transform .18s ease, box-shadow .18s ease, filter .18s ease
    }

    .comm-hero-btn:hover {
        transform: translateY(-1px);
        filter: saturate(1.05);
        box-shadow: 0 24px 38px rgba(8, 145, 178, .28)
    }

    .comm-hero-btn.secondary {
        color: #0f172a;
        background: #fff;
        border: 1px solid #dbeafe;
        box-shadow: 0 10px 24px rgba(15, 23, 42, .08)
    }

    .comm-composer-launch {
        border-radius: 24px;
        border: 1px solid rgba(186, 230, 253, .95);
        background: linear-gradient(135deg, rgba(255, 255, 255, .98) 0%, rgba(236, 254, 255, .92) 100%);
        padding: 18px
    }

    .comm-composer-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, .66);
        backdrop-filter: blur(7px);
        display: none;
        z-index: 119
    }

    .comm-composer {
        display: none
    }

    .comm-composer.is-modal {
        display: flex;
        flex-direction: column;
        position: fixed;
        inset: 24px;
        width: min(1180px, calc(100vw - 48px));
        height: min(920px, calc(100vh - 48px));
        margin: auto;
        z-index: 120;
        background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        box-shadow: 0 36px 90px rgba(15, 23, 42, .34);
        overflow-y: auto;
        overscroll-behavior: contain;
        -webkit-overflow-scrolling: touch;
        border-radius: 32px
    }

    .comm-composer.is-modal summary {
        display: none
    }

    .comm-composer.is-modal .comm-accordion-body {
        display: flex;
        flex-direction: column;
        flex: 1 1 auto;
        padding: 0
    }

    body.comm-composer-open {
        overflow: hidden
    }

    body.comm-composer-open .comm-composer-backdrop {
        display: block
    }

    .comm-composer-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 20px;
        position: sticky;
        top: 0;
        z-index: 2;
        flex: 0 0 auto;
        padding: 26px 28px 18px;
        border-bottom: 1px solid rgba(226, 232, 240, .9);
        background: linear-gradient(135deg, rgba(236, 254, 255, .96) 0%, rgba(239, 246, 255, .98) 100%)
    }

    .comm-composer-close {
        width: 44px;
        height: 44px;
        border-radius: 9999px;
        border: 1px solid #dbeafe;
        background: #fff;
        color: #0f172a;
        font-size: 18px;
        font-weight: 900
    }

    .comm-composer-scroll {
        padding: 24px 28px 28px;
        overflow: visible;
        min-height: auto
    }

    .comm-form-grid {
        display: grid;
        gap: 16px;
        grid-template-columns: repeat(2, minmax(0, 1fr))
    }

    .comm-form-field {
        display: flex;
        flex-direction: column;
        gap: 8px
    }

    .comm-form-field.full {
        grid-column: 1 / -1
    }

    .comm-form-label {
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .14em;
        color: #0891b2
    }

    .comm-input-shell {
        display: flex;
        align-items: center;
        gap: 12px;
        border: 1px solid #cbd5e1;
        border-radius: 22px;
        background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
        padding: 0 16px;
        min-height: 58px;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .72), 0 10px 24px rgba(15, 23, 42, .04)
    }

    .comm-input-shell.accent {
        border-color: #7dd3fc;
        background: linear-gradient(180deg, #ffffff 0%, #ecfeff 100%);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .82), 0 14px 26px rgba(8, 145, 178, .08)
    }

    .comm-input-shell i {
        color: #0891b2;
        font-size: 16px
    }

    .comm-input-shell input,
    .comm-input-shell select,
    .comm-input-shell textarea,
    .comm-field-plain {
        width: 100%;
        border: 0;
        background: transparent;
        color: #0f172a;
        font-size: 14px;
        padding: 15px 0;
        outline: 0;
        box-shadow: none
    }

    .comm-input-shell textarea {
        min-height: 132px;
        resize: vertical
    }

    .comm-highlight-grid {
        display: grid;
        gap: 16px;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        margin-top: 2px
    }

    .comm-highlight-card {
        border-radius: 24px;
        border: 1px solid rgba(125, 211, 252, .9);
        background: linear-gradient(135deg, rgba(255, 255, 255, .98) 0%, rgba(236, 254, 255, .96) 100%);
        padding: 16px;
        box-shadow: 0 18px 30px rgba(8, 145, 178, .08)
    }

    .comm-highlight-card small {
        display: block;
        margin-top: 6px;
        color: #475569;
        font-size: 12px
    }

    .comm-chip {
        display: inline-flex;
        align-items: center;
        border-radius: 9999px;
        padding: 6px 12px;
        font-size: 12px;
        font-weight: 700
    }

    .comm-chip.todo {
        background: #fef3c7;
        color: #92400e
    }

    .comm-chip.in_progress {
        background: #dbeafe;
        color: #1d4ed8
    }

    .comm-chip.done {
        background: #dcfce7;
        color: #166534
    }

    .comm-chip.blocked {
        background: #fee2e2;
        color: #b91c1c
    }

    .comm-chip.cancelled {
        background: #e2e8f0;
        color: #475569
    }

    .comm-task {
        border: 1px solid rgba(219, 234, 254, .92);
        border-radius: 26px;
        padding: 20px;
        background: linear-gradient(135deg, rgba(255, 255, 255, .98) 0%, rgba(248, 250, 252, .96) 42%, rgba(239, 246, 255, .9) 100%);
        box-shadow: 0 16px 34px rgba(15, 23, 42, .05);
        transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease
    }

    .comm-task:hover {
        transform: translateY(-2px);
        border-color: rgba(125, 211, 252, .92);
        box-shadow: 0 22px 42px rgba(15, 23, 42, .08)
    }

    .comm-task.is-late {
        border-color: #fca5a5;
        background: linear-gradient(180deg, #fff 0%, #fff1f2 100%)
    }

    .comm-stat {
        border-radius: 20px;
        padding: 14px 16px
    }

    .comm-stat strong {
        display: block;
        font-size: 28px;
        line-height: 1.1;
        color: #0f172a
    }

    .comm-filter-btn {
        border: 1px solid #dbeafe;
        border-radius: 9999px;
        padding: 8px 12px;
        font-size: 12px;
        font-weight: 700;
        color: #334155;
        background: #fff
    }

    .comm-filter-btn.is-active {
        background: #0f172a;
        color: #fff;
        border-color: #0f172a
    }

    .comm-dropzone {
        position: relative;
        border: 1.5px dashed #7dd3fc;
        border-radius: 22px;
        background: linear-gradient(180deg, rgba(255, 255, 255, .95) 0%, rgba(236, 254, 255, .95) 100%);
        padding: 18px;
        transition: border-color .2s ease, transform .2s ease, box-shadow .2s ease
    }

    .comm-dropzone.is-dragover {
        border-color: #0891b2;
        transform: translateY(-1px);
        box-shadow: 0 14px 30px rgba(8, 145, 178, .12)
    }

    .comm-dropzone-input {
        display: none
    }

    .comm-dropzone-preview {
        width: 56px;
        height: 56px;
        border-radius: 18px;
        background: #e0f2fe;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        flex: 0 0 auto
    }

    .comm-dropzone-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover
    }

    .comm-file-card {
        display: flex;
        align-items: center;
        gap: 12px;
        border-radius: 20px;
        border: 1px solid #dbeafe;
        background: #fff;
        padding: 12px 14px
    }

    .comm-file-remove {
        width: 34px;
        height: 34px;
        border: 0;
        border-radius: 9999px;
        background: #fee2e2;
        color: #b91c1c;
        font-weight: 700
    }

    .comm-task-attachments {
        margin-top: 16px;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 12px
    }

    .comm-task-attachment {
        display: flex;
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
        border-radius: 20px;
        border: 1px solid #dbeafe;
        background: #eff6ff;
        padding: 10px;
        color: #1e3a8a;
        font-size: 12px;
        font-weight: 700
    }

    .comm-task-attachment-thumb {
        height: 118px;
        border-radius: 16px;
        overflow: hidden;
        background: linear-gradient(135deg, #cffafe 0%, #dbeafe 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
        width: 100%;
        border: 0;
        padding: 0;
        cursor: pointer
    }

    .comm-task-attachment-thumb img,
    .comm-task-attachment-thumb iframe {
        width: 100%;
        height: 100%;
        border: 0;
        object-fit: cover;
        background: #fff
    }

    .comm-task-attachment-thumb.is-pdf {
        background: linear-gradient(135deg, #fee2e2 0%, #ffe4e6 100%)
    }

    .comm-task-attachment-thumb.is-file {
        background: linear-gradient(135deg, #e2e8f0 0%, #f8fafc 100%)
    }

    .comm-task-attachment-ext {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 56px;
        height: 56px;
        border-radius: 18px;
        background: rgba(255, 255, 255, .9);
        color: #0f172a;
        font-size: 13px;
        font-weight: 900;
        letter-spacing: .08em;
        text-transform: uppercase
    }

    .comm-task-attachment-title {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        color: #0f172a
    }

    .comm-task-attachment-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 6px
    }

    .comm-task-attachment-link {
        border-radius: 9999px;
        padding: 5px 9px;
        background: #fff;
        color: #0f172a
    }

    .comm-task-attachment-delete {
        border-radius: 9999px;
        padding: 5px 9px;
        background: #fee2e2;
        color: #b91c1c;
        border: 0
    }

    .comm-task-attachment-more {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 118px;
        border-radius: 20px;
        border: 1px dashed #bfdbfe;
        background: #f8fbff;
        color: #475569;
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .08em
    }

    .comm-task-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px
    }

    .comm-task-action-btn {
        border-radius: 16px;
        border: 1px solid #dbeafe;
        background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
        color: #0f172a;
        padding: 10px 14px;
        font-size: 13px;
        font-weight: 800;
        box-shadow: 0 10px 22px rgba(15, 23, 42, .05)
    }

    .comm-task-action-btn.danger {
        border-color: #fecaca;
        background: #fff1f2;
        color: #b91c1c
    }

    .comm-view-switch {
        display: inline-flex;
        flex-wrap: wrap;
        gap: 8px;
        padding: 6px;
        border-radius: 9999px;
        background: #e2e8f0
    }

    .comm-view-switch-btn {
        border: 0;
        border-radius: 9999px;
        padding: 9px 14px;
        font-size: 12px;
        font-weight: 800;
        color: #475569;
        background: transparent
    }

    .comm-view-switch-btn.is-active {
        background: #fff;
        color: #0f172a;
        box-shadow: 0 8px 20px rgba(15, 23, 42, .08)
    }

    .comm-focus-grid {
        display: grid;
        gap: 18px;
        grid-template-columns: 1.1fr .9fr;
        margin-top: 18px
    }

    .comm-focus-card {
        border-radius: 26px;
        border: 1px solid rgba(186, 230, 253, .9);
        background: linear-gradient(135deg, rgba(255, 255, 255, .98) 0%, rgba(236, 254, 255, .92) 100%);
        padding: 20px
    }

    .comm-focus-kpis {
        display: grid;
        gap: 12px;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        margin-top: 16px
    }

    .comm-focus-kpi {
        border-radius: 20px;
        padding: 14px;
        background: #fff
    }

    .comm-focus-kpi strong {
        display: block;
        margin-top: 8px;
        font-size: 28px;
        line-height: 1
    }

    .comm-focus-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-top: 16px
    }

    .comm-focus-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border-radius: 18px;
        border: 1px solid #dbeafe;
        background: rgba(255, 255, 255, .86);
        padding: 12px 14px
    }

    .comm-focus-item.is-late {
        border-color: #fecaca;
        background: #fff1f2
    }

    .comm-focus-empty {
        border-radius: 18px;
        border: 1px dashed #bfdbfe;
        background: rgba(255, 255, 255, .75);
        padding: 18px;
        color: #475569;
        text-align: center
    }

    .comm-push-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
        max-height: 260px;
        overflow-y: auto;
        padding-right: 4px
    }

    .comm-push-item {
        border-radius: 18px;
        border: 1px solid #dbeafe;
        background: rgba(255, 255, 255, .92);
        padding: 12px 14px
    }

    .comm-push-empty {
        border-radius: 18px;
        border: 1px dashed #cbd5e1;
        background: rgba(248, 250, 252, .92);
        padding: 16px;
        color: #475569;
        font-size: 13px
    }

    .comm-target-preview-list {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        max-height: 220px;
        overflow-y: auto;
        padding-right: 4px
    }

    .comm-target-preview-item {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border-radius: 9999px;
        border: 1px solid #dbeafe;
        background: rgba(255, 255, 255, .96);
        padding: 8px 12px;
        max-width: 100%
    }

    .comm-target-preview-item strong {
        color: #0f172a;
        font-size: 13px;
        font-weight: 800
    }

    .comm-target-preview-item span {
        color: #475569;
        font-size: 11px;
        font-weight: 700;
        white-space: nowrap
    }

    .comm-target-preview-empty {
        border-radius: 18px;
        border: 1px dashed #cbd5e1;
        background: rgba(248, 250, 252, .92);
        padding: 14px;
        color: #475569;
        font-size: 13px
    }

    .comm-task-stream {
        margin-top: 24px;
        border-radius: 26px;
        border: 1px solid rgba(186, 230, 253, .95);
        background: linear-gradient(180deg, rgba(255, 255, 255, .98) 0%, rgba(248, 250, 252, .96) 100%);
        padding: 18px;
        min-height: 0;
        display: flex;
        flex-direction: column;
        overflow: hidden
    }

    .comm-task-stream-list {
        margin-top: 18px;
        min-height: 320px;
        max-height: calc(100vh - 430px);
        overflow-y: auto;
        padding-right: 6px
    }

    .comm-task-stream-toolbar {
        margin-top: 16px;
        display: grid;
        grid-template-columns: minmax(0, 1fr) 180px;
        gap: 12px
    }

    .comm-task-stream-toolbar input,
    .comm-task-stream-toolbar select {
        border: 1px solid #cbd5e1;
        border-radius: 18px;
        background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
        padding: 12px 14px;
        font-size: 13px;
        color: #334155;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .72), 0 8px 18px rgba(15, 23, 42, .04)
    }

    .comm-task-upload-input {
        display: none
    }

    .comm-task-inline-dropzone {
        border: 1.5px dashed #bfdbfe;
        border-radius: 18px;
        background: #f8fbff;
        padding: 12px 14px;
        text-align: center;
        color: #475569;
        font-size: 12px;
        font-weight: 700;
        transition: border-color .2s ease, background .2s ease, transform .2s ease;
        cursor: pointer
    }

    .comm-task-inline-dropzone.is-dragover {
        border-color: #0891b2;
        background: #ecfeff;
        transform: translateY(-1px)
    }

    .comm-preview-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, .72);
        backdrop-filter: blur(6px);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 24px;
        z-index: 70
    }

    .comm-preview-overlay.is-open {
        display: flex
    }

    .comm-preview-modal {
        width: min(1100px, 100%);
        max-height: calc(100vh - 48px);
        display: flex;
        flex-direction: column;
        border-radius: 28px;
        overflow: hidden;
        background: #fff;
        box-shadow: 0 30px 90px rgba(15, 23, 42, .35)
    }

    .comm-preview-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 18px 22px;
        border-bottom: 1px solid #e2e8f0
    }

    .comm-preview-body {
        min-height: 360px;
        background: #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 18px
    }

    .comm-preview-nav {
        display: inline-flex;
        align-items: center;
        gap: 8px
    }

    .comm-preview-nav button {
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #0f172a;
        border-radius: 9999px;
        width: 40px;
        height: 40px;
        font-size: 18px;
        font-weight: 800
    }

    .comm-preview-nav button:disabled {
        opacity: .35;
        cursor: not-allowed
    }

    .comm-preview-body img,
    .comm-preview-body iframe,
    .comm-preview-body video,
    .comm-preview-body audio {
        max-width: 100%;
        width: 100%;
        border: 0;
        border-radius: 18px;
        background: #fff
    }

    .comm-preview-body img,
    .comm-preview-body iframe,
    .comm-preview-body video {
        max-height: calc(100vh - 220px);
        object-fit: contain
    }

    .comm-preview-empty {
        width: 100%;
        max-width: 420px;
        border-radius: 24px;
        background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        padding: 28px;
        text-align: center
    }

    @media (max-width: 1024px) {
        .comm-shell {
            grid-template-columns: 1fr
        }

        .comm-composer.is-modal {
            inset: 12px;
            width: auto;
            height: calc(100vh - 24px)
        }

        .comm-composer-head,
        .comm-composer-scroll {
            padding-left: 18px;
            padding-right: 18px
        }

        .comm-form-grid,
        .comm-highlight-grid {
            grid-template-columns: 1fr
        }

        .comm-sidebar,
        .comm-main {
            position: static;
            max-height: none;
            min-height: 0
        }

        .comm-task-stream-list {
            max-height: none
        }

        .comm-task-stream-toolbar {
            grid-template-columns: 1fr
        }

        .comm-focus-grid {
            grid-template-columns: 1fr
        }

        .comm-focus-kpis {
            grid-template-columns: 1fr
        }
    }
</style>
<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(224,231,255,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(186,230,253,0.85),_transparent_34%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-600">Mes tâches</p>
                <h1 class="mt-2 text-3xl font-semibold text-slate-950">Gestion moderne des tâches du personnel</h1>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="dashboard_communication.php" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Dashboard</a>
                <a href="mes_taches_communication.php" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Mes tâches</a>
                <a href="messagerie_communication.php" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Messagerie</a>
                <a href="pieces_jointes_communication.php" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Pièces jointes</a>
            </div>
        </div>

        <div class="comm-shell">
            <aside class="comm-panel comm-sidebar">
                <div class="comm-sidebar-scroll">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-600">Collaborateur</div>
                        <select id="assigneeSelect" class="mt-3 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700"></select>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-600">Recherche</div>
                        <input id="searchInput" type="text" placeholder="Titre, description, nom..." class="mt-3 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700" />
                    </div>

                    <div class="comm-composer-launch">
                        <div class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-600">Actions</div>
                        <div class="mt-2 text-base font-semibold text-slate-950">Créer une tâche</div>
                        <div class="mt-1 text-sm text-slate-600">Toute la logique de création, de ciblage, de pièces jointes et d'édition s'ouvre maintenant dans le popup dédié.</div>
                        <button type="button" data-open-task-composer class="comm-hero-btn mt-4 w-full justify-center"><i class="fas fa-sparkles"></i><span>Ajouter une tâche</span></button>
                    </div>

                    <details id="taskComposer" class="comm-accordion comm-composer">
                        <summary>
                            <span>Création et ciblage</span>
                        </summary>
                        <div class="comm-accordion-body">
                            <div class="comm-composer-head">
                                <div>
                                    <div class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-600">Composer</div>
                                    <div id="taskFormHeading" class="mt-2 text-2xl font-semibold text-slate-950">Créer une tâche</div>
                                    <div class="mt-2 max-w-3xl text-sm text-slate-600">Le formulaire garde le même moteur d'ajout, de modification, d'envoi de mails, de gestion des pièces jointes et de créations groupées. Seule la présentation a été externalisée pour une lecture beaucoup plus nette.</div>
                                </div>
                                <button id="closeTaskComposerBtn" type="button" class="comm-composer-close" aria-label="Fermer le formulaire">×</button>
                            </div>
                            <div class="comm-composer-scroll">
                                <div class="comm-form-grid">
                                    <label class="comm-form-field">
                                        <span class="comm-form-label">Portée de création</span>
                                        <div class="comm-input-shell">
                                            <i class="fas fa-bullseye"></i>
                                            <select id="taskTargetScope" class="comm-field-plain">
                                                <option value="single">Affectation individuelle</option>
                                                <option value="selected">Personnes cochées</option>
                                                <option value="service">Tout un service</option>
                                                <option value="team">Tout le personnel</option>
                                            </select>
                                        </div>
                                    </label>
                                    <div id="taskAssigneeSingleWrapper" class="comm-form-field">
                                        <span class="comm-form-label">Collaborateur ciblé</span>
                                        <div class="comm-input-shell">
                                            <i class="fas fa-user"></i>
                                            <select id="taskAssigneeSelect" class="comm-field-plain"></select>
                                        </div>
                                    </div>
                                    <div id="taskAssigneeServiceWrapper" class="hidden comm-form-field full rounded-2xl border border-cyan-100 bg-white p-4">
                                        <span class="comm-form-label">Service ciblé</span>
                                        <div class="comm-input-shell">
                                            <i class="fas fa-building-user"></i>
                                            <select id="taskServiceSelect" class="comm-field-plain"></select>
                                        </div>
                                    </div>
                                    <div id="taskAssigneeMultiWrapper" class="hidden comm-form-field full rounded-2xl border border-cyan-100 bg-white p-4">
                                        <span class="comm-form-label">Sélection multiple</span>
                                        <div class="comm-input-shell">
                                            <i class="fas fa-users-viewfinder"></i>
                                            <input id="taskAssigneeFilter" type="text" placeholder="Filtrer les collaborateurs..." class="comm-field-plain" />
                                        </div>
                                        <div id="taskAssigneeMultiList" class="max-h-56 space-y-2 overflow-y-auto pr-1"></div>
                                    </div>
                                </div>
                                <div id="taskTargetHint" class="mt-4 rounded-2xl border border-dashed border-cyan-200 bg-white/80 px-4 py-3 text-xs font-medium text-slate-600">Choisissez la portée de création. En mode groupe, une tâche distincte sera créée pour chaque collaborateur ciblé.</div>
                                <div id="taskTargetPreviewWrapper" class="hidden mt-4 rounded-2xl border border-slate-200 bg-white/85 p-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-600">Prévisualisation</div>
                                            <div class="mt-1 text-sm font-semibold text-slate-900">Collaborateurs ciblés automatiquement</div>
                                            <div id="taskTargetPreviewMeta" class="mt-1 text-xs text-slate-500">Le listing s'ajuste selon la portée choisie.</div>
                                        </div>
                                        <div id="taskTargetPreviewCount" class="rounded-full border border-cyan-100 bg-cyan-50 px-3 py-1 text-xs font-semibold text-cyan-800">0</div>
                                    </div>
                                    <div id="taskTargetPreviewList" class="comm-target-preview-list mt-4"></div>
                                </div>
                                <div id="taskGroupApplyWrapper" class="hidden mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                    <label class="flex items-start gap-3">
                                        <input id="taskApplyToGroup" type="checkbox" class="mt-1 h-4 w-4 rounded border-amber-300 text-amber-600 focus:ring-amber-500" />
                                        <span>
                                            <span id="taskApplyToGroupLabel" class="block font-semibold">Appliquer à toute la série groupée</span>
                                            <span class="mt-1 block text-xs text-amber-800/80">Utile pour modifier ou annuler en une seule action toutes les tâches issues de la même création groupée. Les affectations individuelles sont conservées.</span>
                                        </span>
                                    </label>
                                </div>
                                <div class="comm-form-grid mt-5">
                                    <label class="comm-form-field full">
                                        <span class="comm-form-label">Titre de la tâche</span>
                                        <div class="comm-input-shell accent">
                                            <i class="fas fa-pen-nib"></i>
                                            <input id="taskTitle" type="text" placeholder="Titre de la tâche" class="comm-field-plain" />
                                        </div>
                                    </label>
                                    <label class="comm-form-field full">
                                        <span class="comm-form-label">Description détaillée</span>
                                        <div class="comm-input-shell accent">
                                            <i class="fas fa-align-left self-start mt-4"></i>
                                            <textarea id="taskDescription" rows="4" placeholder="Description, consignes, livrable attendu..." class="comm-field-plain"></textarea>
                                        </div>
                                    </label>
                                </div>
                                <div class="comm-highlight-grid mt-5">
                                    <label class="comm-highlight-card">
                                        <span class="comm-form-label">Date et heure</span>
                                        <div class="mt-3 comm-input-shell accent">
                                            <i class="fas fa-calendar-days"></i>
                                            <input id="taskDeadline" type="datetime-local" class="comm-field-plain" />
                                        </div>
                                        <small>Sélecteur prioritaire pour planifier le rendu exact attendu.</small>
                                    </label>
                                    <label class="comm-highlight-card">
                                        <span class="comm-form-label">Durée de la tâche en minute</span>
                                        <div class="mt-3 comm-input-shell accent">
                                            <i class="fas fa-hourglass-half"></i>
                                            <input id="taskDuration" type="number" min="15" step="15" value="60" placeholder="Durée estimée (minutes)" class="comm-field-plain" />
                                        </div>
                                        <small>Renseignez un temps réaliste pour améliorer le suivi et les relances.</small>
                                    </label>
                                </div>
                                <div class="comm-form-grid mt-5">
                                    <label class="comm-form-field">
                                        <span class="comm-form-label">Priorité</span>
                                        <div class="comm-input-shell">
                                            <i class="fas fa-layer-group"></i>
                                            <select id="taskPriority" class="comm-field-plain">
                                                <option value="normal">Priorité normale</option>
                                                <option value="low">Priorité faible</option>
                                                <option value="high">Priorité haute</option>
                                                <option value="urgent">Priorité urgente</option>
                                            </select>
                                        </div>
                                    </label>
                                </div>
                                <div id="taskDropzone" class="comm-dropzone mt-5">
                                    <input id="taskAttachments" type="file" multiple class="comm-dropzone-input" />
                                    <div class="flex items-start gap-4">
                                        <div class="comm-dropzone-preview"><span class="text-lg font-black text-cyan-700">+</span></div>
                                        <div class="min-w-0 flex-1">
                                            <div class="text-sm font-semibold text-slate-900">Pièces jointes de la tâche</div>
                                            <div class="mt-1 text-sm text-slate-600">Glissez-déposez vos fichiers ici ou utilisez le bouton pour sélectionner. L'aperçu apparaît avant l'enregistrement et vous pouvez retirer chaque fichier.</div>
                                            <div class="mt-3 flex flex-wrap items-center gap-3">
                                                <button id="taskBrowseBtn" type="button" class="rounded-2xl bg-white px-4 py-2 text-sm font-semibold text-cyan-700 ring-1 ring-cyan-200 transition hover:bg-cyan-50">Choisir des fichiers</button>
                                                <span class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Images, PDF, audio, vidéo, documents</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div id="taskAttachmentsPreview" class="mt-4 space-y-3"></div>
                                </div>
                                <div class="mt-5 flex flex-wrap gap-3">
                                    <button id="createTaskBtn" type="button" class="comm-hero-btn flex-1 justify-center"><i class="fas fa-paper-plane"></i><span>Ajouter la tâche</span></button>
                                    <button id="cancelEditBtn" type="button" class="hidden comm-hero-btn secondary"><i class="fas fa-rotate-left"></i><span>Annuler</span></button>
                                </div>
                                <div id="formMessage" class="mt-4 rounded-2xl border border-transparent px-4 py-3 text-sm text-slate-600 transition-all duration-150"></div>
                            </div>
                        </div>
                    </details>
                </div>
            </aside>

            <main class="comm-panel comm-main">
                <div class="grid gap-4 md:grid-cols-5">
                    <div class="comm-stat bg-slate-50"><span class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Total</span><strong id="summaryTotal">0</strong></div>
                    <div class="comm-stat bg-amber-50"><span class="text-xs font-semibold uppercase tracking-[0.2em] text-amber-600">À faire</span><strong id="summaryTodo">0</strong></div>
                    <div class="comm-stat bg-sky-50"><span class="text-xs font-semibold uppercase tracking-[0.2em] text-sky-600">En cours</span><strong id="summaryInProgress">0</strong></div>
                    <div class="comm-stat bg-emerald-50"><span class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-600">Terminées</span><strong id="summaryDone">0</strong></div>
                    <div class="comm-stat bg-slate-100"><span class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-600">Annulées</span><strong id="summaryCancelled">0</strong></div>
                </div>
                <div class="mt-6 flex items-center justify-between gap-3">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-600">Backlog</div>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Tâches du collaborateur sélectionné</h2>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <div id="viewSwitch" class="comm-view-switch">
                            <button type="button" class="comm-view-switch-btn is-active" data-view-mode="all">Vue équipe</button>
                            <button type="button" class="comm-view-switch-btn" data-view-mode="mine">Mon focus</button>
                        </div>
                        <div id="currentAssigneeLabel" class="rounded-full bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-700">Tous les collaborateurs</div>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <div class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-600">Statut</div>
                    <div id="statusFilters" class="flex flex-wrap gap-2"></div>
                </div>
                <details class="comm-accordion" open>
                    <summary>
                        <span>Vue focus et actions prioritaires</span>
                    </summary>
                    <div class="comm-accordion-body">
                        <div class="comm-focus-grid">
                            <section class="comm-focus-card">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <div class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-600">Mon suivi</div>
                                        <h3 class="mt-2 text-xl font-semibold text-slate-950">Tableau perso façon To Do</h3>
                                    </div>
                                    <button id="focusMineBtn" type="button" class="rounded-2xl border border-cyan-100 bg-white px-4 py-2 text-sm font-semibold text-cyan-700 transition hover:bg-cyan-50">Afficher mes tâches</button>
                                </div>
                                <div id="myFocusStats" class="comm-focus-kpis"></div>
                            </section>
                            <section class="comm-focus-card">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <div class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-600">Prochaines actions</div>
                                        <h3 class="mt-2 text-xl font-semibold text-slate-950">Ce qui mérite votre attention</h3>
                                    </div>
                                    <button id="clearMineViewBtn" type="button" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Réinitialiser</button>
                                </div>
                                <div id="myFocusList" class="comm-focus-list"></div>
                            </section>
                        </div>
                    </div>
                </details>
                <section class="comm-task-stream">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-600">Listing détaillé</div>
                            <h3 class="mt-1 text-lg font-semibold text-slate-950">Conteneur des tâches</h3>
                        </div>
                        <div id="taskListCount" class="rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700">0 tâche</div>
                    </div>
                    <div class="comm-task-stream-toolbar">
                        <input id="taskQuickFilter" type="text" placeholder="Filtrer dans le backlog affiché..." />
                        <select id="taskQuickMode">
                            <option value="all">Toutes</option>
                            <option value="late">En retard</option>
                            <option value="today">Aujourd'hui</option>
                            <option value="upcoming">À venir</option>
                        </select>
                    </div>
                    <div id="tasksList" class="comm-task-stream-list space-y-4"></div>
                </section>
            </main>
        </div>
    </div>
</div>
<div id="taskComposerBackdrop" class="comm-composer-backdrop" aria-hidden="true"></div>
<div id="previewModal" class="comm-preview-overlay" aria-hidden="true">
    <div class="comm-preview-modal" role="dialog" aria-modal="true" aria-labelledby="previewTitle">
        <div class="comm-preview-header">
            <div class="min-w-0">
                <div id="previewTitle" class="truncate text-lg font-semibold text-slate-950">Aperçu</div>
                <div id="previewMeta" class="mt-1 text-sm text-slate-500"></div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <div class="comm-preview-nav">
                    <button id="previewPrevBtn" type="button" aria-label="Pièce précédente">‹</button>
                    <button id="previewNextBtn" type="button" aria-label="Pièce suivante">›</button>
                </div>
                <a id="previewDownloadLink" href="#" class="rounded-2xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-cyan-700">Télécharger</a>
                <button id="previewCloseBtn" type="button" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Fermer</button>
            </div>
        </div>
        <div id="previewBody" class="comm-preview-body"></div>
    </div>
</div>
<script>
    const state = {
        users: [],
        me: null,
        statuses: {},
        priorities: {},
        currentStatus: '',
        currentAssignee: '',
        search: '',
        editingTaskId: 0,
        editingTaskStatus: 'todo',
        editingTaskGroupCount: 0,
        viewMode: 'all',
        previewItems: [],
        previewIndex: 0,
        previewTaskId: 0,
        pendingTaskId: 0,
        loadedTasks: [],
        taskQuickFilter: '',
        taskQuickMode: 'all'
    };
    const assigneeSelect = document.getElementById('assigneeSelect');
    const taskAssigneeSelect = document.getElementById('taskAssigneeSelect');
    const taskTargetScope = document.getElementById('taskTargetScope');
    const taskAssigneeSingleWrapper = document.getElementById('taskAssigneeSingleWrapper');
    const taskAssigneeServiceWrapper = document.getElementById('taskAssigneeServiceWrapper');
    const taskServiceSelect = document.getElementById('taskServiceSelect');
    const taskAssigneeMultiWrapper = document.getElementById('taskAssigneeMultiWrapper');
    const taskAssigneeMultiList = document.getElementById('taskAssigneeMultiList');
    const taskAssigneeFilter = document.getElementById('taskAssigneeFilter');
    const taskTargetHint = document.getElementById('taskTargetHint');
    const taskTargetPreviewWrapper = document.getElementById('taskTargetPreviewWrapper');
    const taskTargetPreviewMeta = document.getElementById('taskTargetPreviewMeta');
    const taskTargetPreviewCount = document.getElementById('taskTargetPreviewCount');
    const taskTargetPreviewList = document.getElementById('taskTargetPreviewList');
    const taskGroupApplyWrapper = document.getElementById('taskGroupApplyWrapper');
    const taskApplyToGroup = document.getElementById('taskApplyToGroup');
    const taskApplyToGroupLabel = document.getElementById('taskApplyToGroupLabel');
    const searchInput = document.getElementById('searchInput');
    const statusFilters = document.getElementById('statusFilters');
    const tasksList = document.getElementById('tasksList');
    const taskListCount = document.getElementById('taskListCount');
    const taskQuickFilter = document.getElementById('taskQuickFilter');
    const taskQuickMode = document.getElementById('taskQuickMode');
    const currentAssigneeLabel = document.getElementById('currentAssigneeLabel');
    const viewSwitch = document.getElementById('viewSwitch');
    const myFocusStats = document.getElementById('myFocusStats');
    const myFocusList = document.getElementById('myFocusList');
    const focusMineBtn = document.getElementById('focusMineBtn');
    const clearMineViewBtn = document.getElementById('clearMineViewBtn');
    const formMessage = document.getElementById('formMessage');
    const taskFormHeading = document.getElementById('taskFormHeading');
    const createTaskBtn = document.getElementById('createTaskBtn');
    const cancelEditBtn = document.getElementById('cancelEditBtn');
    const taskAttachmentsInput = document.getElementById('taskAttachments');
    const taskDropzone = document.getElementById('taskDropzone');
    const taskAttachmentsPreview = document.getElementById('taskAttachmentsPreview');
    const taskComposer = document.getElementById('taskComposer');
    const taskComposerBackdrop = document.getElementById('taskComposerBackdrop');
    const openTaskComposerBtn = document.getElementById('openTaskComposerBtn');
    const closeTaskComposerBtn = document.getElementById('closeTaskComposerBtn');
    const previewModal = document.getElementById('previewModal');
    const previewTitle = document.getElementById('previewTitle');
    const previewMeta = document.getElementById('previewMeta');
    const previewBody = document.getElementById('previewBody');
    const previewPrevBtn = document.getElementById('previewPrevBtn');
    const previewNextBtn = document.getElementById('previewNextBtn');
    const previewDownloadLink = document.getElementById('previewDownloadLink');
    const previewCloseBtn = document.getElementById('previewCloseBtn');
    const selectedTaskFiles = [];
    const maxTaskUploadBytes = <?= (int)$maxTaskUploadBytes ?>;
    const maxTaskUploadLabel = <?= json_encode($maxTaskUploadLabel, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function openTaskComposer() {
        if (!taskComposer) {
            return;
        }
        if (taskComposer.parentElement !== document.body) {
            document.body.appendChild(taskComposer);
        }
        document.body.classList.add('comm-composer-open');
        taskComposer.classList.add('is-modal');
        taskComposer.setAttribute('open', 'open');
        taskComposerBackdrop?.setAttribute('aria-hidden', 'false');
        window.setTimeout(() => {
            document.getElementById('taskTitle')?.focus();
        }, 80);
    }

    function closeTaskComposer() {
        if (!taskComposer) {
            return;
        }
        document.body.classList.remove('comm-composer-open');
        taskComposer.classList.remove('is-modal');
        taskComposer.removeAttribute('open');
        taskComposerBackdrop?.setAttribute('aria-hidden', 'true');
    }

    function setFormMessage(message, tone = 'neutral') {
        const tones = {
            neutral: ['border-transparent', 'bg-transparent', 'text-slate-600'],
            success: ['border-emerald-200', 'bg-emerald-50', 'text-emerald-800'],
            info: ['border-sky-200', 'bg-sky-50', 'text-sky-800'],
            warning: ['border-amber-200', 'bg-amber-50', 'text-amber-900'],
            error: ['border-rose-200', 'bg-rose-50', 'text-rose-800']
        };
        const current = tones[tone] || tones.neutral;
        Object.values(tones).flat().forEach(cssClass => formMessage.classList.remove(cssClass));
        current.forEach(cssClass => formMessage.classList.add(cssClass));
        formMessage.textContent = String(message || '');
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, s => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        } [s]));
    }

    function formatDateTime(iso) {
        if (!iso) {
            return 'Sans date';
        }
        const date = new Date(iso);
        return Number.isNaN(date.getTime()) ? iso : date.toLocaleString('fr-FR', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function formatBytes(value) {
        const size = Number(value || 0);
        if (size <= 0) return '0 Ko';
        if (size >= 1024 * 1024) return (size / (1024 * 1024)).toFixed(1) + ' Mo';
        return Math.max(1, Math.round(size / 1024)) + ' Ko';
    }

    function filterUploadableFiles(files) {
        const accepted = [];
        const rejected = [];

        Array.from(files || []).forEach(file => {
            if (maxTaskUploadBytes > 0 && Number(file.size || 0) > maxTaskUploadBytes) {
                rejected.push(`${file.name} (${formatBytes(file.size || 0)}) dépasse la limite serveur de ${maxTaskUploadLabel}.`);
                return;
            }
            accepted.push(file);
        });

        return {
            accepted,
            rejected
        };
    }

    function isToday(dateValue) {
        if (!dateValue) {
            return false;
        }
        const date = new Date(dateValue);
        if (Number.isNaN(date.getTime())) {
            return false;
        }
        const now = new Date();
        return date.getFullYear() === now.getFullYear() && date.getMonth() === now.getMonth() && date.getDate() === now.getDate();
    }

    function isUpcoming(dateValue) {
        if (!dateValue) {
            return false;
        }
        const date = new Date(dateValue);
        if (Number.isNaN(date.getTime())) {
            return false;
        }
        return date.getTime() >= Date.now();
    }

    function assetUrl(path) {
        const value = String(path || '');
        if (value === '') {
            return '';
        }
        return value.startsWith('../') ? value : '../' + value;
    }

    function isPreviewableAttachment(item) {
        const mime = String(item?.type || item?.mime_type || '').toLowerCase();
        return mime.startsWith('image/') || mime.startsWith('video/') || mime === 'application/pdf';
    }

    function isImageAttachment(item) {
        return String(item?.type || item?.mime_type || '').toLowerCase().startsWith('image/');
    }

    function isPdfAttachment(item) {
        return String(item?.type || item?.mime_type || '').toLowerCase() === 'application/pdf';
    }

    function isVideoAttachment(item) {
        return String(item?.type || item?.mime_type || '').toLowerCase().startsWith('video/');
    }

    function isAudioAttachment(item) {
        return String(item?.type || item?.mime_type || '').toLowerCase().startsWith('audio/');
    }

    function fileSignature(file) {
        return [file.name, file.size, file.lastModified, file.type].join('::');
    }

    function revokeFilePreview(file) {
        if (file && file.__previewUrl) {
            URL.revokeObjectURL(file.__previewUrl);
            delete file.__previewUrl;
        }
    }

    function removeSelectedFile(index) {
        const removed = selectedTaskFiles.splice(index, 1)[0];
        revokeFilePreview(removed);
        renderSelectedFiles();
    }

    function renderSelectedFiles() {
        if (!selectedTaskFiles.length) {
            taskAttachmentsPreview.innerHTML = '<div class="rounded-2xl border border-dashed border-slate-200 bg-white/70 px-4 py-4 text-sm text-slate-500">Aucun fichier préparé pour cette tâche.</div>';
            return;
        }

        taskAttachmentsPreview.innerHTML = selectedTaskFiles.map((file, index) => {
            const preview = isPreviewableAttachment(file) && String(file.type || '').toLowerCase().startsWith('image/') ?
                `<img src="${escapeHtml(file.__previewUrl || '')}" alt="${escapeHtml(file.name)}" />` :
                `<span class="text-xs font-black uppercase text-cyan-700">${escapeHtml((file.name.split('.').pop() || 'file').slice(0, 4))}</span>`;

            return `<div class="comm-file-card"><div class="comm-dropzone-preview">${preview}</div><div class="min-w-0 flex-1"><div class="truncate text-sm font-semibold text-slate-900">${escapeHtml(file.name)}</div><div class="mt-1 text-xs text-slate-500">${escapeHtml(formatBytes(file.size))} ${isPreviewableAttachment(file) ? '• aperçu disponible' : ''}</div></div><button type="button" class="comm-file-remove" data-remove-file="${index}">×</button></div>`;
        }).join('');

        taskAttachmentsPreview.querySelectorAll('[data-remove-file]').forEach(button => {
            button.addEventListener('click', () => {
                removeSelectedFile(Number(button.getAttribute('data-remove-file') || '0'));
            });
        });
    }

    function addSelectedFiles(files) {
        const {
            accepted,
            rejected
        } = filterUploadableFiles(files);
        accepted.forEach(file => {
            const signature = fileSignature(file);
            if (selectedTaskFiles.some(existing => fileSignature(existing) === signature)) {
                return;
            }
            if (String(file.type || '').toLowerCase().startsWith('image/')) {
                file.__previewUrl = URL.createObjectURL(file);
            }
            selectedTaskFiles.push(file);
        });
        if (rejected.length) {
            setFormMessage(rejected[0], 'warning');
        }
        renderSelectedFiles();
    }

    function resetSelectedFiles() {
        selectedTaskFiles.splice(0, selectedTaskFiles.length).forEach(revokeFilePreview);
        renderSelectedFiles();
    }

    async function fetchJson(url, options = {}) {
        const res = await fetch(url, options);
        const text = await res.text();
        const data = text ? JSON.parse(text) : null;
        if (!res.ok || !data?.ok) {
            throw new Error(data?.error || 'Erreur serveur');
        }
        return data;
    }

    function renderViewSwitch() {
        viewSwitch.querySelectorAll('[data-view-mode]').forEach(button => {
            button.classList.toggle('is-active', button.getAttribute('data-view-mode') === state.viewMode);
        });
    }

    function renderMyFocus(tasks) {
        const myTasks = tasks.filter(task => String(task.assignee_user_id || '') === String(state.me?.id || '0'));
        const pendingTasks = myTasks.filter(task => !['done', 'cancelled'].includes(task.status));
        const lateTasks = pendingTasks.filter(task => task.is_late);
        const todayTasks = pendingTasks.filter(task => isToday(task.deadline_iso));
        const inProgressTasks = myTasks.filter(task => task.status === 'in_progress');
        const nextTasks = pendingTasks
            .slice()
            .sort((left, right) => new Date(left.deadline_iso || 0).getTime() - new Date(right.deadline_iso || 0).getTime())
            .slice(0, 4);

        myFocusStats.innerHTML = [{
                label: 'Aujourd\'hui',
                value: todayTasks.length,
                tone: 'text-amber-600'
            },
            {
                label: 'En cours',
                value: inProgressTasks.length,
                tone: 'text-sky-600'
            },
            {
                label: 'En retard',
                value: lateTasks.length,
                tone: 'text-rose-600'
            }
        ].map(item => `<div class="comm-focus-kpi"><span class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">${escapeHtml(item.label)}</span><strong class="${item.tone}">${Number(item.value || 0).toLocaleString('fr-FR')}</strong></div>`).join('');

        if (!nextTasks.length) {
            myFocusList.innerHTML = '<div class="comm-focus-empty">Aucune tâche personnelle urgente. Votre espace est dégagé.</div>';
            return;
        }

        myFocusList.innerHTML = nextTasks.map(task => `<button type="button" class="comm-focus-item ${task.is_late ? 'is-late' : ''}" data-focus-task="${task.id}"><div class="min-w-0 text-left"><div class="truncate text-sm font-semibold text-slate-950">${escapeHtml(task.title)}</div><div class="mt-1 text-xs text-slate-500">${escapeHtml(formatDateTime(task.deadline_iso))} • ${escapeHtml(task.status_label)}</div></div><div class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-700">${escapeHtml(task.priority_label)}</div></button>`).join('');

        myFocusList.querySelectorAll('[data-focus-task]').forEach(button => {
            button.addEventListener('click', () => {
                const taskId = Number(button.getAttribute('data-focus-task') || '0');
                const task = tasks.find(item => Number(item.id || 0) === taskId);
                if (task) {
                    startEditTask(task);
                }
            });
        });
    }

    function taskAttachmentThumbnail(taskId, attachment) {
        if (isImageAttachment(attachment)) {
            return `<button type="button" class="comm-task-attachment-thumb js-preview-attachment" data-task-id="${taskId}" data-attachment-id="${attachment.id}"><img src="${assetUrl(attachment.preview_url)}" alt="${escapeHtml(attachment.original_name)}" loading="lazy" /></button>`;
        }
        if (isPdfAttachment(attachment)) {
            return `<button type="button" class="comm-task-attachment-thumb is-pdf js-preview-attachment" data-task-id="${taskId}" data-attachment-id="${attachment.id}"><iframe src="${assetUrl(attachment.preview_url)}#toolbar=0&navpanes=0&scrollbar=0" title="${escapeHtml(attachment.original_name)}" loading="lazy"></iframe></button>`;
        }

        return `<button type="button" class="comm-task-attachment-thumb is-file js-preview-attachment" data-task-id="${taskId}" data-attachment-id="${attachment.id}"><span class="comm-task-attachment-ext">${escapeHtml((attachment.extension || 'file').slice(0, 4))}</span></button>`;
    }

    async function deleteAttachment(attachmentId) {
        const formData = new FormData();
        formData.set('attachment_id', String(attachmentId || 0));
        const data = await fetchJson('../api/communication_attachment_delete.php', {
            method: 'POST',
            body: formData
        });
        return data;
    }

    async function deleteTask(taskId) {
        const formData = new FormData();
        formData.set('action', 'delete_task');
        formData.set('task_id', String(taskId || 0));
        return fetchJson('../api/communication_send.php', {
            method: 'POST',
            body: formData
        });
    }

    async function uploadAttachmentsToTask(taskId, files) {
        const {
            accepted,
            rejected
        } = filterUploadableFiles(files);
        if (rejected.length) {
            throw new Error(rejected[0]);
        }
        const list = accepted;
        if (!taskId || !list.length) {
            return;
        }
        const formData = new FormData();
        formData.set('action', 'upload_attachments');
        formData.set('task_id', String(taskId));
        list.forEach(file => formData.append('attachments[]', file));
        return fetchJson('../api/communication_send.php', {
            method: 'POST',
            body: formData
        });
    }

    async function loadTaskAttachments(taskId) {
        const data = await fetchJson('../api/communication_attachments.php?task_id=' + encodeURIComponent(String(taskId || 0)) + '&limit=250');
        return Array.isArray(data.attachments) ? data.attachments : [];
    }

    function renderPreviewItem(item) {
        const previewType = String(item?.mime_type || item?.type || '').toLowerCase();
        const previewUrl = String(item?.preview_url || item?.previewUrl || '');

        if (previewUrl && previewType.startsWith('image/')) {
            previewBody.innerHTML = `<img src="${assetUrl(previewUrl)}" alt="${escapeHtml(item?.original_name || item?.name || 'Aperçu')}" />`;
        } else if (previewUrl && previewType === 'application/pdf') {
            previewBody.innerHTML = `<iframe src="${assetUrl(previewUrl)}#toolbar=1&navpanes=0" title="${escapeHtml(item?.original_name || item?.name || 'Aperçu')}"></iframe>`;
        } else if (previewUrl && previewType.startsWith('video/')) {
            previewBody.innerHTML = `<video src="${assetUrl(previewUrl)}" controls autoplay></video>`;
        } else if (previewUrl && previewType.startsWith('audio/')) {
            previewBody.innerHTML = `<div class="comm-preview-empty"><div class="text-base font-semibold text-slate-900">Lecture audio</div><div class="mt-2 text-sm text-slate-600">Le fichier est prêt à être écouté dans cette fenêtre.</div><audio class="mt-5" src="${assetUrl(previewUrl)}" controls autoplay></audio></div>`;
        } else if (previewUrl) {
            previewBody.innerHTML = `<iframe src="${assetUrl(previewUrl)}" title="${escapeHtml(item?.original_name || item?.name || 'Aperçu')}"></iframe>`;
        } else {
            previewBody.innerHTML = `<div class="comm-preview-empty"><div class="text-base font-semibold text-slate-900">Aperçu non disponible</div><div class="mt-2 text-sm text-slate-600">Ce format ne peut pas être affiché directement. Utilisez le bouton de téléchargement.</div></div>`;
        }
    }

    function syncPreviewModal() {
        const currentItem = state.previewItems[state.previewIndex] || null;
        if (!currentItem) {
            closePreviewModal();
            return;
        }
        previewTitle.textContent = currentItem.original_name || currentItem.name || 'Aperçu';
        previewMeta.textContent = [formatBytes(currentItem.file_size || 0), currentItem.mime_type || currentItem.type || '', `${state.previewIndex + 1}/${state.previewItems.length}`].filter(Boolean).join(' • ');
        previewDownloadLink.href = assetUrl(currentItem.download_url || currentItem.downloadUrl || '');
        previewPrevBtn.disabled = state.previewIndex <= 0;
        previewNextBtn.disabled = state.previewIndex >= state.previewItems.length - 1;
        renderPreviewItem(currentItem);
        previewModal.classList.add('is-open');
        previewModal.setAttribute('aria-hidden', 'false');
    }

    async function openPreviewModalForTask(taskId, attachmentId) {
        if (!taskId) {
            return;
        }
        const items = await loadTaskAttachments(taskId);
        if (!items.length) {
            throw new Error('Aucune pièce jointe disponible pour cette tâche.');
        }
        state.previewTaskId = taskId;
        state.previewItems = items;
        const foundIndex = items.findIndex(item => Number(item.id || 0) === Number(attachmentId || 0));
        state.previewIndex = foundIndex >= 0 ? foundIndex : 0;
        syncPreviewModal();
    }

    function closePreviewModal() {
        previewModal.classList.remove('is-open');
        previewModal.setAttribute('aria-hidden', 'true');
        previewBody.innerHTML = '';
    }

    function syncSingleAssigneeSelection() {
        if (state.editingTaskId > 0) {
            return;
        }
        const preferredAssigneeId = String(state.currentAssignee || '');
        const hasPreferredAssignee = preferredAssigneeId !== '' && Array.from(taskAssigneeSelect.options).some(option => option.value === preferredAssigneeId);
        taskAssigneeSelect.value = hasPreferredAssignee ? preferredAssigneeId : '';
    }

    function renderUsers() {
        const userOptions = state.users.map(user => `<option value="${user.id}">${escapeHtml(user.display)}</option>`).join('');
        const selectedValues = Array.from(taskAssigneeMultiList.querySelectorAll('input[type="checkbox"]:checked')).map(input => String(input.value || ''));
        const currentServiceValue = String(taskServiceSelect.value || '');
        const serviceMap = new Map();
        state.users.forEach(user => {
            const serviceId = Number(user.service_id || 0);
            if (serviceId > 0 && !serviceMap.has(serviceId)) {
                serviceMap.set(serviceId, String(user.service_label || 'Service #' + serviceId));
            }
        });
        const serviceOptions = Array.from(serviceMap.entries())
            .sort((left, right) => String(left[1]).localeCompare(String(right[1]), 'fr', {
                sensitivity: 'base'
            }))
            .map(([serviceId, serviceLabel]) => `<option value="${serviceId}">${escapeHtml(serviceLabel)}</option>`)
            .join('');

        assigneeSelect.innerHTML = ['<option value="">Tous les collaborateurs</option>', userOptions].join('');
        taskAssigneeSelect.innerHTML = ['<option value="">Affecter à un collaborateur</option>', userOptions].join('');
        taskServiceSelect.innerHTML = ['<option value="">Sélectionner un service</option>', serviceOptions].join('');
        if (currentServiceValue && Array.from(taskServiceSelect.options).some(option => option.value === currentServiceValue)) {
            taskServiceSelect.value = currentServiceValue;
        } else {
            taskServiceSelect.value = '';
        }
        taskAssigneeMultiList.innerHTML = state.users.map(user => {
            const serviceLabel = String(user.service_label || 'Sans service');
            const searchValue = [user.display, serviceLabel, user.mail_pro || ''].join(' ').toLowerCase();
            const checked = selectedValues.includes(String(user.id)) ? 'checked' : '';
            return `<label class="task-bulk-person-row flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3" data-search="${escapeHtml(searchValue)}"><input type="checkbox" value="${user.id}" class="mt-1 h-4 w-4 rounded border-slate-300 text-cyan-600 focus:ring-cyan-500" ${checked}><span class="min-w-0 flex-1"><span class="block text-sm font-semibold text-slate-900">${escapeHtml(user.display)}</span><span class="mt-1 block text-xs text-slate-500">${escapeHtml(serviceLabel)}</span></span></label>`;
        }).join('');
        assigneeSelect.value = state.viewMode === 'mine' ? String(state.me?.id || '') : state.currentAssignee;
        syncSingleAssigneeSelection();
        filterBulkAssigneeRows();
        updateTaskTargetVisibility();
        renderTaskTargetPreview();
        updateAssigneeLabel();
        renderViewSwitch();

        taskAssigneeMultiList.querySelectorAll('input[type="checkbox"]').forEach(input => {
            input.addEventListener('change', renderTaskTargetPreview);
        });
    }

    function renderTaskTargetPreview() {
        const isEditing = state.editingTaskId > 0;
        const scope = isEditing ? 'single' : String(taskTargetScope.value || 'single');
        let users = [];
        let meta = 'Le listing s\'ajuste selon la portée choisie.';

        if (scope === 'single') {
            const assigneeId = String(taskAssigneeSelect.value || state.currentAssignee || '');
            users = assigneeId ? state.users.filter(user => String(user.id || '') === assigneeId) : [];
            meta = users.length ? 'Le collaborateur ciblé est renseigné automatiquement à partir du filtre de gauche.' : 'Sélectionnez un collaborateur dans le filtre de gauche pour renseigner automatiquement la cible.';
        } else if (scope === 'selected') {
            const selectedIds = getSelectedBulkAssigneeIds();
            users = state.users.filter(user => selectedIds.includes(String(user.id || '')));
            meta = 'Prévisualisation des collaborateurs actuellement cochés.';
        } else if (scope === 'service') {
            const serviceId = Number(taskServiceSelect.value || 0);
            users = serviceId > 0 ? state.users.filter(user => Number(user.service_id || 0) === serviceId) : [];
            meta = serviceId > 0 ? 'Tous les collaborateurs actifs du service sélectionné.' : 'Sélectionnez un service pour voir la liste ciblée.';
        } else if (scope === 'team') {
            users = state.users.slice();
            meta = 'Tous les membres du personnel disposant d\'un compte utilisateur sont ciblés automatiquement.';
        }

        taskTargetPreviewWrapper.classList.remove('hidden');
        taskTargetPreviewMeta.textContent = meta;
        taskTargetPreviewCount.textContent = Number(users.length || 0).toLocaleString('fr-FR');

        if (!users.length) {
            taskTargetPreviewList.innerHTML = '<div class="comm-target-preview-empty">Aucun collaborateur ciblé à afficher pour cette portée pour le moment.</div>';
            return;
        }

        taskTargetPreviewList.innerHTML = users.map(user => {
            const serviceLabel = String(user.service_label || 'Sans service');
            return `<div class="comm-target-preview-item"><strong>${escapeHtml(user.display)}</strong><span>${escapeHtml(serviceLabel)}</span></div>`;
        }).join('');
    }

    function getSelectedBulkAssigneeIds() {
        return Array.from(taskAssigneeMultiList.querySelectorAll('input[type="checkbox"]:checked'))
            .map(input => String(input.value || ''))
            .filter(Boolean);
    }

    function filterBulkAssigneeRows() {
        const query = String(taskAssigneeFilter.value || '').trim().toLowerCase();
        taskAssigneeMultiList.querySelectorAll('.task-bulk-person-row').forEach(row => {
            const haystack = String(row.getAttribute('data-search') || '');
            row.classList.toggle('hidden', query !== '' && !haystack.includes(query));
        });
    }

    function updateTaskTargetVisibility() {
        const isEditing = state.editingTaskId > 0;
        const scope = isEditing ? 'single' : String(taskTargetScope.value || 'single');
        taskTargetScope.disabled = isEditing;
        taskAssigneeSingleWrapper.classList.add('hidden');
        taskAssigneeServiceWrapper.classList.toggle('hidden', scope !== 'service');
        taskAssigneeMultiWrapper.classList.toggle('hidden', scope !== 'selected');

        if (scope === 'single') {
            syncSingleAssigneeSelection();
        }

        if (scope === 'team') {
            taskTargetHint.textContent = 'Une tâche distincte sera créée pour chaque membre du personnel disposant d\'un compte utilisateur. La liste ci-dessous est informative.';
        } else if (scope === 'service') {
            taskTargetHint.textContent = 'Une tâche distincte sera créée pour chaque membre du personnel ayant un compte utilisateur dans le service choisi. La liste ci-dessous est informative.';
        } else if (scope === 'selected') {
            taskTargetHint.textContent = 'Cochez les collaborateurs à cibler. Chacun recevra sa propre tâche.';
        } else {
            taskTargetHint.textContent = state.currentAssignee ? 'La tâche sera affectée au collaborateur actuellement sélectionné dans le filtre de gauche.' : 'Sélectionnez d\'abord un collaborateur dans le filtre de gauche pour une affectation individuelle.';
        }

        renderTaskTargetPreview();
    }

    function resetTaskForm(options = {}) {
        state.editingTaskId = 0;
        state.editingTaskStatus = 'todo';
        state.editingTaskGroupCount = 0;
        taskFormHeading.textContent = 'Créer une tâche';
        createTaskBtn.textContent = 'Ajouter la tâche';
        cancelEditBtn.classList.add('hidden');
        document.getElementById('taskTitle').value = '';
        document.getElementById('taskDescription').value = '';
        document.getElementById('taskDeadline').value = '';
        document.getElementById('taskDuration').value = '60';
        document.getElementById('taskPriority').value = 'normal';
        document.getElementById('taskAttachments').value = '';
        taskTargetScope.value = 'single';
        taskAssigneeSelect.value = options.keepCurrentAssignee && state.currentAssignee ? state.currentAssignee : '';
        taskServiceSelect.value = '';
        taskAssigneeFilter.value = '';
        taskAssigneeMultiList.querySelectorAll('input[type="checkbox"]').forEach(input => {
            input.checked = false;
        });
        taskApplyToGroup.checked = false;
        taskGroupApplyWrapper.classList.add('hidden');
        taskApplyToGroupLabel.textContent = 'Appliquer à toute la série groupée';
        updateTaskTargetVisibility();
        filterBulkAssigneeRows();
        resetSelectedFiles();
    }

    function startEditTask(task) {
        state.editingTaskId = Number(task.id || 0);
        state.editingTaskStatus = String(task.status || 'todo');
        state.editingTaskGroupCount = Number(task.grouped_task_count || 0);
        taskFormHeading.textContent = 'Modifier la tâche';
        createTaskBtn.textContent = 'Enregistrer les modifications';
        cancelEditBtn.classList.remove('hidden');
        taskTargetScope.value = 'single';
        taskAssigneeSelect.value = String(task.assignee_user_id || '');
        document.getElementById('taskTitle').value = String(task.title || '');
        document.getElementById('taskDescription').value = String(task.description || '');
        document.getElementById('taskDeadline').value = String(task.deadline_iso || '').slice(0, 16);
        document.getElementById('taskDuration').value = String(task.duration_minutes || 60);
        document.getElementById('taskPriority').value = String(task.priority || 'normal');
        document.getElementById('taskAttachments').value = '';
        taskApplyToGroup.checked = false;
        if (state.editingTaskGroupCount > 1) {
            taskGroupApplyWrapper.classList.remove('hidden');
            taskApplyToGroupLabel.textContent = `Appliquer à toute la série groupée (${state.editingTaskGroupCount} tâches)`;
        } else {
            taskGroupApplyWrapper.classList.add('hidden');
            taskApplyToGroupLabel.textContent = 'Appliquer à toute la série groupée';
        }
        updateTaskTargetVisibility();
        resetSelectedFiles();
        formMessage.textContent = 'Mode modification actif. Vous pouvez aussi ajouter de nouvelles pièces jointes.';
        openTaskComposer();
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    }

    function renderStatusFilters() {
        const buttons = [`<button type="button" class="comm-filter-btn ${state.currentStatus === '' ? 'is-active' : ''}" data-status="">Tous</button>`]
            .concat(Object.entries(state.statuses).map(([key, label]) => `<button type="button" class="comm-filter-btn ${state.currentStatus === key ? 'is-active' : ''}" data-status="${key}">${escapeHtml(label)}</button>`));
        statusFilters.innerHTML = buttons.join('');
        statusFilters.querySelectorAll('[data-status]').forEach(button => {
            button.addEventListener('click', () => {
                state.currentStatus = button.getAttribute('data-status') || '';
                renderStatusFilters();
                loadTasks();
            });
        });
    }

    function getLocallyFilteredTasks() {
        const needle = String(state.taskQuickFilter || '').trim().toLowerCase();
        return (Array.isArray(state.loadedTasks) ? state.loadedTasks : []).filter(task => {
            if (state.taskQuickMode === 'late' && !task.is_late) {
                return false;
            }
            if (state.taskQuickMode === 'today' && !isToday(task.deadline_iso)) {
                return false;
            }
            if (state.taskQuickMode === 'upcoming' && !isUpcoming(task.deadline_iso)) {
                return false;
            }
            if (needle === '') {
                return true;
            }

            const haystack = [
                task.title,
                task.description,
                task.assignee_display,
                task.creator_display,
                task.priority_label,
                task.status_label,
                task.group_scope_label
            ].join(' ').toLowerCase();

            return haystack.includes(needle);
        });
    }

    function renderTasks(tasks) {
        const visibleCount = Number(tasks.length || 0);
        const totalCount = Number((Array.isArray(state.loadedTasks) ? state.loadedTasks.length : 0) || 0);
        taskListCount.textContent = visibleCount.toLocaleString('fr-FR') + ' / ' + totalCount.toLocaleString('fr-FR') + (totalCount > 1 ? ' tâches' : ' tâche');
        if (!tasks.length) {
            tasksList.innerHTML = '<div class="rounded-[24px] border border-dashed border-slate-300 bg-slate-50 px-6 py-10 text-center text-sm text-slate-500">Aucune tâche trouvée pour ce filtre.</div>';
            return;
        }

        tasksList.innerHTML = tasks.map(task => {
            const attachments = Array.isArray(task.attachments) ? task.attachments : [];
            const attachmentsHtml = attachments.length ? `<div class="comm-task-attachments">${attachments.map(attachment => `<div class="comm-task-attachment">${taskAttachmentThumbnail(task.id, attachment)}<div><div class="comm-task-attachment-title">${escapeHtml(attachment.original_name)}</div><div class="mt-1 text-[11px] font-medium text-slate-500">${escapeHtml(formatBytes(attachment.file_size || 0))}</div></div><div class="comm-task-attachment-actions">${attachment.is_previewable ? `<button type="button" class="comm-task-attachment-link js-preview-attachment" data-task-id="${task.id}" data-attachment-id="${attachment.id}">Consulter</button>` : ''}<a href="${assetUrl(attachment.download_url)}" class="comm-task-attachment-link">Télécharger</a><button type="button" class="comm-task-attachment-delete" data-delete-attachment="${attachment.id}">Supprimer</button></div></div>`).join('')}${Number(task.attachments_count || 0) > attachments.length ? `<div class="comm-task-attachment-more">+ ${Number(task.attachments_count || 0) - attachments.length} autre(s)</div>` : ''}</div>` : '';
            const groupBadgeHtml = Number(task.grouped_task_count || 0) > 1 ? `<span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">Série groupée • ${Number(task.grouped_task_count || 0).toLocaleString('fr-FR')} tâches</span>` : '';

            return `
        <article class="comm-task ${task.is_late ? 'is-late' : ''}">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-xl font-semibold text-slate-950">${escapeHtml(task.title)}</h3>
                        <span class="comm-chip ${task.status}">${escapeHtml(task.status_label)}</span>
                        ${groupBadgeHtml}
                    </div>
                    <p class="mt-3 text-sm leading-6 text-slate-600">${escapeHtml(task.description || 'Aucune description.')}</p>
                    <div class="mt-4 flex flex-wrap gap-3 text-sm text-slate-500">
                        <span><strong class="text-slate-700">Assignée à :</strong> ${escapeHtml(task.assignee_display)}</span>
                        <span><strong class="text-slate-700">Créée par :</strong> ${escapeHtml(task.creator_display)}</span>
                        <span><strong class="text-slate-700">Deadline :</strong> ${escapeHtml(formatDateTime(task.deadline_iso))}</span>
                    </div>
                    ${attachmentsHtml}
                </div>
                <div class="flex w-full max-w-xs flex-col gap-3 xl:w-72">
                    <div class="rounded-2xl bg-slate-50 px-4 py-3 text-sm text-slate-600"><strong class="text-slate-800">Priorité :</strong> ${escapeHtml(task.priority_label)}</div>
                    <div class="rounded-2xl bg-slate-50 px-4 py-3 text-sm text-slate-600"><strong class="text-slate-800">Commentaires :</strong> ${Number(task.comments_count || 0).toLocaleString('fr-FR')} | <strong class="text-slate-800">Pièces jointes :</strong> ${Number(task.attachments_count || 0).toLocaleString('fr-FR')}</div>
                    <select class="task-status-select rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" data-task-id="${task.id}">${Object.entries(state.statuses).map(([key, label]) => `<option value="${key}" ${task.status === key ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('')}</select>
                    <input type="file" multiple class="comm-task-upload-input" data-upload-input="${task.id}" />
                    <div class="comm-task-inline-dropzone" data-upload-task="${task.id}">Déposez ici vos fichiers ou cliquez pour les ajouter à cette tâche</div>
                    <div class="comm-task-actions">
                        <button type="button" class="comm-task-action-btn" data-edit-task="${task.id}">Modifier</button>
                        <button type="button" class="comm-task-action-btn danger" data-delete-task="${task.id}">Supprimer</button>
                    </div>
                </div>
            </div>
        </article>`;
        }).join('');

        tasksList.querySelectorAll('.task-status-select').forEach(select => {
            select.addEventListener('change', async () => {
                const taskId = Number(select.getAttribute('data-task-id') || '0');
                const task = tasks.find(item => Number(item.id || 0) === taskId) || null;
                const formData = new FormData();
                formData.set('action', 'update_status');
                formData.set('task_id', String(taskId));
                formData.set('status', select.value);
                if (task && Number(task.grouped_task_count || 0) > 1) {
                    const applyToGroup = window.confirm(`Cette tâche appartient à une série de ${Number(task.grouped_task_count || 0).toLocaleString('fr-FR')} tâches. Voulez-vous appliquer ce changement à toute la série ?`);
                    if (applyToGroup) {
                        formData.set('apply_to_group', '1');
                    }
                }
                try {
                    const result = await fetchJson('../api/communication_send.php', {
                        method: 'POST',
                        body: formData
                    });
                    if (state.editingTaskId === taskId) {
                        state.editingTaskStatus = select.value;
                    }
                    const infoParts = [];
                    if (result.grouped_notification_emitted && result.grouped_notification_message) {
                        infoParts.push(result.grouped_notification_message);
                    }
                    if (result.warning_message) {
                        infoParts.push(result.warning_message);
                    }
                    if (result.calendar_invite_message) {
                        infoParts.push(result.calendar_invite_message);
                    }
                    if (infoParts.length) {
                        setFormMessage(infoParts.join(' '), result.warning_message ? 'warning' : 'info');
                    }
                    loadTasks();
                } catch (error) {
                    setFormMessage(error.message, 'error');
                }
            });
        });
        tasksList.querySelectorAll('.js-preview-attachment').forEach(button => {
            button.addEventListener('click', async () => {
                try {
                    await openPreviewModalForTask(Number(button.getAttribute('data-task-id') || '0'), Number(button.getAttribute('data-attachment-id') || '0'));
                } catch (error) {
                    formMessage.textContent = error.message;
                }
            });
        });
        tasksList.querySelectorAll('[data-edit-task]').forEach(button => {
            button.addEventListener('click', () => {
                const taskId = Number(button.getAttribute('data-edit-task') || '0');
                const task = tasks.find(item => Number(item.id || 0) === taskId);
                if (task) {
                    startEditTask(task);
                }
            });
        });
        tasksList.querySelectorAll('[data-upload-task]').forEach(button => {
            button.addEventListener('click', () => {
                const taskId = button.getAttribute('data-upload-task') || '';
                const input = tasksList.querySelector(`[data-upload-input="${taskId}"]`);
                if (input) {
                    input.click();
                }
            });
        });
        tasksList.querySelectorAll('[data-upload-task]').forEach(zone => {
            ['dragenter', 'dragover'].forEach(eventName => {
                zone.addEventListener(eventName, event => {
                    event.preventDefault();
                    zone.classList.add('is-dragover');
                });
            });
            ['dragleave', 'drop'].forEach(eventName => {
                zone.addEventListener(eventName, event => {
                    event.preventDefault();
                    zone.classList.remove('is-dragover');
                });
            });
            zone.addEventListener('drop', async event => {
                const taskId = Number(zone.getAttribute('data-upload-task') || '0');
                const files = event.dataTransfer?.files;
                if (!taskId || !files || !files.length) {
                    return;
                }
                try {
                    setFormMessage('Ajout des pièces jointes...', 'neutral');
                    await uploadAttachmentsToTask(taskId, files);
                    setFormMessage('Pièces jointes ajoutées à la tâche.', 'success');
                    await loadTasks();
                } catch (error) {
                    setFormMessage(error.message, 'error');
                }
            });
        });
        tasksList.querySelectorAll('[data-upload-input]').forEach(input => {
            input.addEventListener('change', async () => {
                const taskId = Number(input.getAttribute('data-upload-input') || '0');
                const files = input.files;
                if (!taskId || !files || !files.length) {
                    return;
                }
                try {
                    setFormMessage('Ajout des pièces jointes...', 'neutral');
                    await uploadAttachmentsToTask(taskId, files);
                    setFormMessage('Pièces jointes ajoutées à la tâche.', 'success');
                    input.value = '';
                    await loadTasks();
                } catch (error) {
                    setFormMessage(error.message, 'error');
                }
            });
        });
        tasksList.querySelectorAll('[data-delete-task]').forEach(button => {
            button.addEventListener('click', async () => {
                const taskId = Number(button.getAttribute('data-delete-task') || '0');
                if (!taskId) {
                    return;
                }
                if (!window.confirm('Supprimer cette tâche et tous ses commentaires/pièces jointes ?')) {
                    return;
                }
                try {
                    setFormMessage('Suppression de la tâche...', 'neutral');
                    const result = await deleteTask(taskId);
                    if (state.editingTaskId === taskId) {
                        resetTaskForm({
                            keepCurrentAssignee: true
                        });
                    }
                    const deleteParts = ['Tâche supprimée.'];
                    if (result.calendar_invite_message) {
                        deleteParts.push(result.calendar_invite_message);
                    }
                    setFormMessage(deleteParts.join(' '), result.calendar_invite_message ? 'info' : 'success');
                    await loadTasks();
                } catch (error) {
                    setFormMessage(error.message, 'error');
                }
            });
        });
        tasksList.querySelectorAll('[data-delete-attachment]').forEach(button => {
            button.addEventListener('click', async () => {
                const attachmentId = Number(button.getAttribute('data-delete-attachment') || '0');
                if (!attachmentId) {
                    return;
                }
                if (!window.confirm('Supprimer cette pièce jointe de la tâche ?')) {
                    return;
                }
                try {
                    setFormMessage('Suppression de la pièce jointe...', 'neutral');
                    await deleteAttachment(attachmentId);
                    setFormMessage('Pièce jointe supprimée.', 'success');
                    await loadTasks();
                } catch (error) {
                    setFormMessage(error.message, 'error');
                }
            });
        });

        if (state.pendingTaskId > 0) {
            const pendingTask = tasks.find(task => Number(task.id || 0) === state.pendingTaskId);
            if (pendingTask) {
                startEditTask(pendingTask);
                state.pendingTaskId = 0;
            }
        }
    }

    function updateSummary(summary) {
        document.getElementById('summaryTotal').textContent = Number(summary.total || 0).toLocaleString('fr-FR');
        document.getElementById('summaryTodo').textContent = Number(summary.todo || 0).toLocaleString('fr-FR');
        document.getElementById('summaryInProgress').textContent = Number(summary.in_progress || 0).toLocaleString('fr-FR');
        document.getElementById('summaryDone').textContent = Number(summary.done || 0).toLocaleString('fr-FR');
        document.getElementById('summaryCancelled').textContent = Number(summary.cancelled || 0).toLocaleString('fr-FR');
    }

    function updateAssigneeLabel() {
        if (state.viewMode === 'mine' && state.me) {
            currentAssigneeLabel.textContent = 'Mes tâches • ' + state.me.display;
            return;
        }
        const selected = state.users.find(user => String(user.id) === String(state.currentAssignee));
        currentAssigneeLabel.textContent = selected ? selected.display : 'Tous les collaborateurs';
    }

    async function loadState() {
        const data = await fetchJson('../api/communication_state.php');
        state.me = data.me || null;
        state.users = data.users || [];
        state.statuses = data.statuses || {};
        state.priorities = data.priorities || {};
        renderUsers();
        renderStatusFilters();
    }

    async function loadTasks() {
        state.search = searchInput.value.trim();
        updateAssigneeLabel();
        const params = new URLSearchParams();
        const effectiveAssignee = state.viewMode === 'mine' ? String(state.me?.id || '') : state.currentAssignee;
        if (effectiveAssignee) params.set('assignee_user_id', effectiveAssignee);
        if (state.currentStatus) params.set('status', state.currentStatus);
        if (state.search) params.set('search', state.search);
        const data = await fetchJson('../api/communication_tasks.php?' + params.toString());
        updateSummary(data.summary || {});
        state.loadedTasks = Array.isArray(data.tasks) ? data.tasks : [];
        renderMyFocus(state.loadedTasks);
        renderTasks(getLocallyFilteredTasks());
    }

    async function createTask() {
        const title = document.getElementById('taskTitle').value.trim();
        const description = document.getElementById('taskDescription').value.trim();
        const deadline = document.getElementById('taskDeadline').value;
        const duration = document.getElementById('taskDuration').value || '60';
        const priority = document.getElementById('taskPriority').value;
        const editingTaskId = state.editingTaskId;
        const targetScopeValue = editingTaskId > 0 ? 'single' : String(taskTargetScope.value || 'single');
        const selectedAssigneeIds = getSelectedBulkAssigneeIds();
        if (targetScopeValue === 'single' && !taskAssigneeSelect.value) {
            setFormMessage('Sélectionnez d\'abord un collaborateur dans le filtre de gauche.', 'warning');
            return;
        }
        if (targetScopeValue === 'selected' && !selectedAssigneeIds.length) {
            setFormMessage('Cochez au moins un collaborateur.', 'warning');
            return;
        }
        if (targetScopeValue === 'service' && !taskServiceSelect.value) {
            setFormMessage('Sélectionnez d\'abord un service.', 'warning');
            return;
        }
        const formData = new FormData();
        formData.set('target_scope', targetScopeValue);
        if (targetScopeValue === 'single') {
            formData.set('assignee_user_id', taskAssigneeSelect.value);
        } else if (targetScopeValue === 'selected') {
            selectedAssigneeIds.forEach(userId => formData.append('assignee_user_ids[]', userId));
        } else if (targetScopeValue === 'service') {
            formData.set('service_id', taskServiceSelect.value);
        }
        formData.set('title', title);
        formData.set('description', description);
        formData.set('deadline', deadline);
        formData.set('duration_minutes', duration);
        formData.set('priority', priority);
        if (editingTaskId > 0) {
            formData.set('action', 'update_task');
            formData.set('task_id', String(editingTaskId));
            formData.set('status', state.editingTaskStatus);
            if (state.editingTaskGroupCount > 1 && taskApplyToGroup.checked) {
                formData.set('apply_to_group', '1');
            }
        }
        selectedTaskFiles.forEach(file => formData.append('attachments[]', file));
        try {
            setFormMessage(editingTaskId > 0 ? 'Mise à jour...' : 'Enregistrement...', 'neutral');
            const result = await fetchJson('../api/communication_send.php', {
                method: 'POST',
                body: formData
            });
            resetTaskForm({
                keepCurrentAssignee: true
            });
            const successMessage = editingTaskId > 0 ? 'Tâche mise à jour avec succès.' : ((Number(result.created_count || 0) > 1) ? ('Tâches créées avec succès pour ' + Number(result.created_count || 0).toLocaleString('fr-FR') + ' collaborateurs.') : 'Tâche créée avec succès.');
            const groupedNotificationMessage = result.grouped_notification_emitted && result.grouped_notification_message ? (' ' + result.grouped_notification_message) : '';
            const warningMessage = result.warning_message ? (' ' + result.warning_message) : '';
            const calendarInviteMessage = result.calendar_invite_message ? (' ' + result.calendar_invite_message) : '';
            const messageTone = result.warning_message ? 'warning' : ((result.grouped_notification_emitted || result.calendar_invite_message) ? 'info' : 'success');
            setFormMessage(successMessage + groupedNotificationMessage + warningMessage + calendarInviteMessage, messageTone);
            await loadTasks();
            closeTaskComposer();
        } catch (error) {
            setFormMessage(error.message, 'error');
        }
    }

    taskTargetScope.addEventListener('change', () => {
        updateTaskTargetVisibility();
    });
    taskAssigneeFilter.addEventListener('input', () => {
        filterBulkAssigneeRows();
    });
    taskServiceSelect.addEventListener('change', () => {
        renderTaskTargetPreview();
    });

    assigneeSelect.addEventListener('change', () => {
        state.viewMode = 'all';
        state.currentAssignee = assigneeSelect.value;
        syncSingleAssigneeSelection();
        updateTaskTargetVisibility();
        renderViewSwitch();
        loadTasks();
    });
    viewSwitch.querySelectorAll('[data-view-mode]').forEach(button => {
        button.addEventListener('click', () => {
            state.viewMode = button.getAttribute('data-view-mode') || 'all';
            if (state.viewMode === 'mine' && state.me) {
                state.currentAssignee = String(state.me.id || '');
            }
            renderUsers();
            loadTasks();
        });
    });
    focusMineBtn.addEventListener('click', () => {
        state.viewMode = 'mine';
        if (state.me) {
            state.currentAssignee = String(state.me.id || '');
        }
        renderUsers();
        loadTasks();
    });
    clearMineViewBtn.addEventListener('click', () => {
        state.viewMode = 'all';
        state.currentAssignee = '';
        state.currentStatus = '';
        state.search = '';
        assigneeSelect.value = '';
        searchInput.value = '';
        renderUsers();
        renderStatusFilters();
        loadTasks();
    });
    searchInput.addEventListener('input', () => {
        clearTimeout(window.__commSearch);
        window.__commSearch = setTimeout(loadTasks, 250);
    });
    taskQuickFilter.addEventListener('input', () => {
        state.taskQuickFilter = taskQuickFilter.value.trim();
        renderTasks(getLocallyFilteredTasks());
    });
    taskQuickMode.addEventListener('change', () => {
        state.taskQuickMode = taskQuickMode.value || 'all';
        renderTasks(getLocallyFilteredTasks());
    });
    document.getElementById('taskBrowseBtn').addEventListener('click', () => {
        taskAttachmentsInput.click();
    });
    taskAttachmentsInput.addEventListener('change', () => {
        addSelectedFiles(taskAttachmentsInput.files);
        taskAttachmentsInput.value = '';
    });
    ['dragenter', 'dragover'].forEach(eventName => {
        taskDropzone.addEventListener(eventName, event => {
            event.preventDefault();
            taskDropzone.classList.add('is-dragover');
        });
    });
    ['dragleave', 'drop'].forEach(eventName => {
        taskDropzone.addEventListener(eventName, event => {
            event.preventDefault();
            taskDropzone.classList.remove('is-dragover');
        });
    });
    taskDropzone.addEventListener('drop', event => {
        addSelectedFiles(event.dataTransfer?.files || []);
    });
    createTaskBtn.addEventListener('click', createTask);
    cancelEditBtn.addEventListener('click', () => {
        resetTaskForm({
            keepCurrentAssignee: true
        });
        formMessage.textContent = 'Mode création rétabli.';
        closeTaskComposer();
    });
    openTaskComposerBtn?.addEventListener('click', () => {
        resetTaskForm({
            keepCurrentAssignee: true
        });
        setFormMessage('Préparez une tâche claire puis validez pour lancer l\'enregistrement et les notifications habituelles.', 'info');
        openTaskComposer();
    });
    document.querySelectorAll('[data-open-task-composer]').forEach(button => {
        button.addEventListener('click', () => {
            resetTaskForm({
                keepCurrentAssignee: true
            });
            setFormMessage('Préparez une tâche claire puis validez pour lancer l\'enregistrement et les notifications habituelles.', 'info');
            openTaskComposer();
        });
    });
    closeTaskComposerBtn?.addEventListener('click', closeTaskComposer);
    taskComposerBackdrop?.addEventListener('click', closeTaskComposer);
    previewCloseBtn.addEventListener('click', closePreviewModal);
    previewPrevBtn.addEventListener('click', () => {
        if (state.previewIndex > 0) {
            state.previewIndex -= 1;
            syncPreviewModal();
        }
    });
    previewNextBtn.addEventListener('click', () => {
        if (state.previewIndex < state.previewItems.length - 1) {
            state.previewIndex += 1;
            syncPreviewModal();
        }
    });
    previewModal.addEventListener('click', event => {
        if (event.target === previewModal) {
            closePreviewModal();
        }
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && previewModal.classList.contains('is-open')) {
            closePreviewModal();
        }
        if (event.key === 'Escape' && document.body.classList.contains('comm-composer-open')) {
            closeTaskComposer();
        }
        if (event.key === 'ArrowLeft' && previewModal.classList.contains('is-open') && state.previewIndex > 0) {
            state.previewIndex -= 1;
            syncPreviewModal();
        }
        if (event.key === 'ArrowRight' && previewModal.classList.contains('is-open') && state.previewIndex < state.previewItems.length - 1) {
            state.previewIndex += 1;
            syncPreviewModal();
        }
    });

    document.addEventListener('DOMContentLoaded', async () => {
        const url = new URL(window.location.href);
        const mineFlag = url.searchParams.get('mine') || '';
        state.currentAssignee = url.searchParams.get('assignee_user_id') || '';
        state.pendingTaskId = Number(url.searchParams.get('task_id') || '0');
        if (taskComposer && taskComposer.parentElement !== document.body) {
            document.body.appendChild(taskComposer);
        }
        resetTaskForm({
            keepCurrentAssignee: true
        });
        await loadState();
        if (mineFlag === '1' && state.me) {
            state.viewMode = 'mine';
            state.currentAssignee = String(state.me.id || '');
            renderUsers();
        }
        await loadTasks();
    });
    window.addEventListener('beforeunload', () => {
        selectedTaskFiles.forEach(revokeFilePreview);
    });
</script>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>