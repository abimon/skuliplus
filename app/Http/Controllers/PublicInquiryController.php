<?php

namespace App\Http\Controllers;

use App\Mail\PublicInquiryMail;
use App\Models\CentralRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class PublicInquiryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:contact,demo'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'school' => ['nullable', 'required_if:kind,demo', 'string', 'max:160'],
            'role' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'required_if:kind,contact', 'string', 'max:2000'],
            'website' => ['nullable', 'string', 'max:200'],
        ]);

        if (! empty($data['website'])) {
            return back()->with('sent', [
                $data['kind'] => $this->successMessage($data['kind']),
            ]);
        }

        CentralRequest::create([
            'type' => $data['kind'],
            'subject' => $data['kind'] === 'demo' ? 'Demo request: '.$data['school'] : 'Contact message from '.$data['name'],
            'message' => $data['message'] ?? '',
            'payload' => $data,
            'status' => 'pending',
        ]);

        $recipient = config('services.schoolms.inquiry_email') ?: config('mail.from.address');
        $kind = $data['kind'] === 'demo' ? 'Demo request' : 'Contact message';
        $mail = (new PublicInquiryMail($kind, $data))->replyTo($data['email'], $data['name']);
        Mail::to($recipient)->send($mail);

        return back()->with('sent', [
            $data['kind'] => $this->successMessage($data['kind']),
        ]);
    }

    private function successMessage(string $kind): string
    {
        return $kind === 'demo'
            ? 'Thanks for reaching out. We’ll be in touch to arrange your school demo.'
            : 'Thanks for your message. Our team will get back to you.';
    }
}
