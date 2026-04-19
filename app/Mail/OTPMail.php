<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OTPMail extends Mailable
{
    use Queueable, SerializesModels;

    public $otp;
    public $subjectLine;

    public function __construct($otp, $subjectLine)
    {
        $this->otp = $otp;
        $this->subjectLine = $subjectLine;
    }

    public function build()
    {
        return $this->subject($this->subjectLine)
                    ->html("
                        <div style='font-family: sans-serif; padding: 20px;'>
                            <h2 style='color: #4f46e5;'>BKAD Digital Archive</h2>
                            <p>Kode verifikasi Anda adalah:</p>
                            <h1 style='letter-spacing: 5px; color: #1e293b;'>{$this->otp}</h1>
                            <p>Kode ini akan kadaluarsa dalam 15 menit. Jangan berikan kode ini kepada siapa pun.</p>
                        </div>
                    ");
    }
}