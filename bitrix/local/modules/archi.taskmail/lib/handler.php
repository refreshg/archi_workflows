<?php
// ARCHI — თასქების მეილ-შეტყობინებები (ჩვეულებრივი თასქები).
// მეილი მიდის თასქის ყველა მონაწილეს (შემქმნელი, პასუხისმგებელი, თანაშემსრულებლები, დამკვირვებლები),
// მოქმედების ავტორის გარდა, როცა: თასქი შეიქმნა / დაიწერა კომენტარი / აიტვირთა ფაილი / თასქი დასრულდა.
//
// უსაფრთხოება:
//  - ყოველი handler მთლიანად try/catch-შია: შეცდომა თასქის შენახვას ვერ შეუშლის ხელს.
//  - handler-ები $arFields-ს არ ცვლიან და false-ს არ აბრუნებენ (თასქის განახლებას ვერ გააუქმებენ).
//  - მეილის აწყობა და გაგზავნა ხდება background job-ში, პასუხის გაგზავნის შემდეგ (მომხმარებელს არ ანელებს).
//  - თასქებს არაფერს უცვლის, მხოლოდ კითხულობს.

namespace Archi\TaskMail;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Mail\Mail;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;

class Handler
{
    const ENABLED = true;                 // false = გამორთვა (მოდულის წაშლის გარეშე)
    const PORTAL_URL = 'https://crm.archi.ge';
    const MAIL_FROM = 'ticket@archi.ge';
    const NOTIFY_ACTOR = false;           // true = მოქმედების ავტორსაც გაეგზავნოს
    const STATUS_COMPLETED = 5;

    // სატესტოდ: თუ ცარიელი არაა, ყველა მეილი მხოლოდ ამ მისამართებზე წავა (რეალურ მონაწილეებს არა).
    const TEST_ONLY_TO = array();

    // OnBeforeTaskUpdate → OnTaskUpdate: სტატუსი და ფაილები განახლებამდე
    private static $before = array();

    // ------------------------------------------------------------------ event handlers

    public static function onTaskAdd($taskId = 0, $fields = array())
    {
        try {
            if (!self::ENABLED) {
                return;
            }
            $taskId = (int)$taskId;
            $actorId = self::currentUserId();
            if (!$actorId && is_array($fields) && !empty($fields['CREATED_BY'])) {
                $actorId = (int)$fields['CREATED_BY'];
            }
            self::defer(function () use ($taskId, $actorId) {
                self::sendTaskCreated($taskId, $actorId);
            });
        } catch (\Throwable $e) {
            self::log('onTaskAdd', $e);
        }
    }

    public static function onBeforeTaskUpdate($taskId = 0, $fields = array())
    {
        try {
            if (!self::ENABLED) {
                return;
            }
            $taskId = (int)$taskId;
            $task = self::loadTask($taskId);
            self::$before[$taskId] = array(
                'STATUS' => $task ? (int)$task['STATUS'] : null,
                'FILES' => self::taskFileIds($taskId),
            );
        } catch (\Throwable $e) {
            self::log('onBeforeTaskUpdate', $e);
        }
        // არაფერს ვაბრუნებთ: მხოლოდ false აუქმებს განახლებას
    }

    public static function onTaskUpdate($taskId = 0, $fields = array(), $taskBefore = array())
    {
        try {
            if (!self::ENABLED) {
                return;
            }
            $taskId = (int)$taskId;
            $fields = is_array($fields) ? $fields : array();
            $before = isset(self::$before[$taskId]) ? self::$before[$taskId] : null;
            unset(self::$before[$taskId]);

            $oldStatus = $before ? $before['STATUS'] : null;
            if ($oldStatus === null && is_array($taskBefore) && isset($taskBefore['REAL_STATUS'])) {
                $oldStatus = (int)$taskBefore['REAL_STATUS'];
            }
            $newStatus = isset($fields['STATUS']) ? (int)$fields['STATUS'] : null;
            $closed = $newStatus === self::STATUS_COMPLETED && $oldStatus !== self::STATUS_COMPLETED;

            $oldFiles = ($before && array_key_exists('UF_TASK_WEBDAV_FILES', $fields)) ? $before['FILES'] : null;

            if (!$closed && $oldFiles === null) {
                return;
            }
            $actorId = self::currentUserId();
            self::defer(function () use ($taskId, $actorId, $closed, $oldFiles) {
                if ($oldFiles !== null) {
                    $newFiles = array_values(array_diff(self::taskFileIds($taskId), $oldFiles));
                    if ($newFiles) {
                        self::sendFilesAdded($taskId, $actorId, $newFiles);
                    }
                }
                if ($closed) {
                    self::sendTaskClosed($taskId, $actorId);
                }
            });
        } catch (\Throwable $e) {
            self::log('onTaskUpdate', $e);
        }
    }

    public static function onCommentAdd($messageId = 0, $data = array())
    {
        try {
            if (!self::ENABLED) {
                return;
            }
            $messageId = (int)$messageId;
            $taskId = (is_array($data) && !empty($data['TASK_ID'])) ? (int)$data['TASK_ID'] : 0;
            self::defer(function () use ($messageId, $taskId) {
                self::sendComment($messageId, $taskId);
            });
        } catch (\Throwable $e) {
            self::log('onCommentAdd', $e);
        }
    }

    // ------------------------------------------------------------------ messages

    private static function sendTaskCreated($taskId, $actorId)
    {
        $task = self::loadTask($taskId);
        if (!$task) {
            return;
        }
        $body = '<p><b>' . self::esc(self::userName($actorId)) . '</b>-მა შექმნა ახალი თასქი.</p>';
        $description = self::bbToText((string)$task['DESCRIPTION']);
        if ($description !== '') {
            $body .= '<p><b>აღწერა:</b><br>' . nl2br(self::esc(self::cut($description, 1500))) . '</p>';
        }
        $body .= self::filesHtml(self::taskFileIds($taskId));
        self::send($task, $actorId, 'ახალი თასქი', $body);
    }

    private static function sendComment($messageId, $taskId)
    {
        if (!Loader::includeModule('forum')) {
            return;
        }
        $message = \CForumMessage::GetByID($messageId);
        if (!$message) {
            return;
        }
        // სისტემური კომენტარები (სტატუსის/დედლაინის ცვლილება და ა.შ.) და თასქის აღწერის პოსტი არ გვინდა
        if (!empty($message['SERVICE_TYPE'])
            || in_array((string)$message['PARAM1'], array('TK', 'AUX'), true)
            || $message['NEW_TOPIC'] === 'Y') {
            return;
        }
        if (!$taskId) {
            $topic = \CForumTopic::GetByID($message['TOPIC_ID']);
            if ($topic && preg_match('/^TASK_(\d+)$/', (string)$topic['XML_ID'], $m)) {
                $taskId = (int)$m[1];
            }
        }
        $task = self::loadTask($taskId);
        if (!$task) {
            return;
        }
        $actorId = (int)$message['AUTHOR_ID'];
        $files = self::ufIds('FORUM_MESSAGE', 'UF_FORUM_MESSAGE_DOC', $messageId);

        $body = '<p><b>' . self::esc(self::userName($actorId)) . '</b>-მა დაწერა კომენტარი:</p>';
        $text = self::bbToText((string)$message['POST_MESSAGE']);
        if ($text !== '') {
            $body .= '<blockquote style="margin:0 0 12px;padding:8px 12px;border-left:3px solid #2fc6f6;background:#f5f7f8;">'
                . nl2br(self::esc(self::cut($text, 3000))) . '</blockquote>';
        }
        $body .= self::filesHtml($files);
        self::send($task, $actorId, $files ? 'ახალი კომენტარი და ფაილი' : 'ახალი კომენტარი', $body);
    }

    private static function sendFilesAdded($taskId, $actorId, array $fileIds)
    {
        $task = self::loadTask($taskId);
        if (!$task) {
            return;
        }
        $body = '<p><b>' . self::esc(self::userName($actorId)) . '</b>-მა თასქზე ატვირთა ფაილი.</p>'
            . self::filesHtml($fileIds);
        self::send($task, $actorId, 'ატვირთულია ფაილი', $body);
    }

    private static function sendTaskClosed($taskId, $actorId)
    {
        $task = self::loadTask($taskId);
        if (!$task) {
            return;
        }
        $body = '<p><b>' . self::esc(self::userName($actorId)) . '</b>-მა დაასრულა თასქი.</p>';
        self::send($task, $actorId, 'თასქი დასრულდა', $body);
    }

    private static function send(array $task, $actorId, $event, $bodyHtml)
    {
        $emails = self::recipients((int)$task['ID'], (int)$actorId);
        if (!$emails) {
            return;
        }
        $url = self::PORTAL_URL . '/company/personal/user/' . (int)$task['RESPONSIBLE_ID']
            . '/tasks/task/view/' . (int)$task['ID'] . '/';
        $deadline = ($task['DEADLINE'] instanceof DateTime) ? $task['DEADLINE']->format('d.m.Y H:i') : '—';

        $subject = '[თასქი #' . (int)$task['ID'] . '] ' . $event . ': ' . self::cut((string)$task['TITLE'], 120);
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#333;">'
            . '<p style="font-size:16px;margin:0 0 12px;"><a href="' . self::esc($url) . '">'
            . self::esc((string)$task['TITLE']) . '</a></p>'
            . $bodyHtml
            . '<p style="color:#777;font-size:12px;margin-top:16px;">'
            . 'პასუხისმგებელი: ' . self::esc(self::userName((int)$task['RESPONSIBLE_ID']))
            . ' &nbsp;|&nbsp; დედლაინი: ' . self::esc($deadline) . '<br>'
            . '<a href="' . self::esc($url) . '">თასქის გახსნა</a></p>'
            . '</div>';

        foreach ($emails as $email) {
            try {
                Mail::send(array(
                    'TO' => $email,
                    'SUBJECT' => $subject,
                    'BODY' => $html,
                    'HEADER' => array('From' => self::MAIL_FROM),
                    'CHARSET' => 'UTF-8',
                    'CONTENT_TYPE' => 'html',
                ));
            } catch (\Throwable $e) {
                self::log('send to ' . $email, $e);
            }
        }
    }

    // ------------------------------------------------------------------ data

    private static function loadTask($taskId)
    {
        if ($taskId <= 0 || !Loader::includeModule('tasks')) {
            return null;
        }
        $task = \Bitrix\Tasks\Internals\TaskTable::getList(array(
            'select' => array('ID', 'TITLE', 'DESCRIPTION', 'STATUS', 'CREATED_BY', 'RESPONSIBLE_ID', 'DEADLINE'),
            'filter' => array('=ID' => $taskId),
        ))->fetch();
        return $task ?: null;
    }

    private static function recipients($taskId, $actorId)
    {
        if (self::TEST_ONLY_TO) {
            return self::TEST_ONLY_TO;
        }
        $userIds = array();
        $rows = \Bitrix\Tasks\Internals\Task\MemberTable::getList(array(
            'select' => array('USER_ID'),
            'filter' => array('=TASK_ID' => $taskId),
        ));
        while ($row = $rows->fetch()) {
            $userIds[(int)$row['USER_ID']] = true;
        }
        if (!self::NOTIFY_ACTOR) {
            unset($userIds[$actorId]);
        }
        unset($userIds[0]);
        if (!$userIds) {
            return array();
        }
        $emails = array();
        $users = UserTable::getList(array(
            'select' => array('ID', 'EMAIL'),
            'filter' => array('@ID' => array_keys($userIds), '=ACTIVE' => 'Y'),
        ));
        while ($user = $users->fetch()) {
            $email = trim((string)$user['EMAIL']);
            if ($email !== '' && check_email($email)) {
                $emails[strtolower($email)] = $email;
            }
        }
        return array_values($emails);
    }

    private static function taskFileIds($taskId)
    {
        return self::ufIds('TASKS_TASK', 'UF_TASK_WEBDAV_FILES', $taskId);
    }

    private static function ufIds($entityId, $field, $id)
    {
        global $USER_FIELD_MANAGER;
        if (!is_object($USER_FIELD_MANAGER) || (int)$id <= 0) {
            return array();
        }
        $value = $USER_FIELD_MANAGER->GetUserFieldValue($entityId, $field, (int)$id);
        if (!is_array($value)) {
            $value = $value ? array($value) : array();
        }
        return array_values(array_filter(array_map('intval', $value)));
    }

    private static function filesHtml(array $attachedIds)
    {
        if (!$attachedIds || !Loader::includeModule('disk')) {
            return '';
        }
        $names = array();
        foreach ($attachedIds as $id) {
            $attached = \Bitrix\Disk\AttachedObject::loadById((int)$id, array('OBJECT'));
            $file = $attached ? $attached->getFile() : null;
            if ($file) {
                $names[] = '<li>' . self::esc($file->getName()) . '</li>';
            }
        }
        return $names ? '<p><b>ფაილები:</b></p><ul style="margin-top:0;">' . implode('', $names) . '</ul>' : '';
    }

    private static function userName($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return 'სისტემა';
        }
        $user = UserTable::getList(array(
            'select' => array('NAME', 'LAST_NAME', 'LOGIN'),
            'filter' => array('=ID' => $userId),
        ))->fetch();
        if (!$user) {
            return '#' . $userId;
        }
        $name = trim($user['NAME'] . ' ' . $user['LAST_NAME']);
        return $name !== '' ? $name : $user['LOGIN'];
    }

    // ------------------------------------------------------------------ helpers

    private static function defer(callable $job)
    {
        $safe = function () use ($job) {
            try {
                $job();
            } catch (\Throwable $e) {
                self::log('job', $e);
            }
        };
        $app = Application::getInstance();
        if (method_exists($app, 'addBackgroundJob')) {
            $app->addBackgroundJob($safe);
        } else {
            $safe();
        }
    }

    private static function currentUserId()
    {
        global $USER;
        return (is_object($USER) && $USER->IsAuthorized()) ? (int)$USER->GetID() : 0;
    }

    private static function bbToText($text)
    {
        $text = preg_replace('/\[USER=\d+\](.*?)\[\/USER\]/is', '$1', $text);
        $text = preg_replace('/\[DISK\s+FILE\s+ID=[^\]]*\]/i', '', $text);
        $text = preg_replace('/\[\/?[a-z*]+(=[^\]]*)?\]/i', '', $text);
        return trim(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
    }

    private static function cut($text, $max)
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }

    private static function esc($text)
    {
        return htmlspecialcharsbx($text);
    }

    private static function log($where, \Throwable $e)
    {
        error_log('[archi.taskmail] ' . $where . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    }
}
