<?php

namespace App\Services;

class BulkMessagingService {
    private $whatsapp;
    private $sms;
    private $email;

    public function __construct($whatsappService, $smsService, $emailService) {
        $this->whatsapp = $whatsappService;
        $this->sms = $smsService;
        $this->email = $emailService;
    }

    public function sendWhatsAppMessage($recipient, $message) {
        return $this->whatsapp->send($recipient, $message);
    }

    public function sendSMSMessage($recipient, $message) {
        return $this->sms->send($recipient, $message);
    }

    public function sendEmail($recipient, $subject, $body) {
        return $this->email->send($recipient, $subject, $body);
    }
}