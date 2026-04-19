<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OTPMail extends Mailable implements ShouldQueue
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
                        <div style='font-family: sans-serif; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
                            <h2 style='color: #4f46e5;'>BKAD Digital Archive</h2>
                            <p>Kode verifikasi Anda adalah:</p>
                            <h1 style='letter-spacing: 5px; color: #1e293b; background: #f1f5f9; padding: 10px; display: inline-block;'>{$this->otp}</h1>
                            <p>Kode ini akan kadaluarsa dalam 15 menit. Jangan berikan kode ini kepada siapa pun.</p>
                            <hr style='border: none; border-top: 1px solid #eee; margin: 20px 0;'>
                            <small style='color: #64748b;'>Ini adalah pesan otomatis, mohon tidak membalas email ini.</small>
                        </div>
                    ");
    }
}
