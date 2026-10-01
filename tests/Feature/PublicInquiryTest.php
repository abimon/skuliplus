<?php

use App\Mail\PublicInquiryMail;
use App\Models\CentralRequest;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

test('public home route renders the SchoolMS landing page', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->where('sent', []));
});

test('contact and demo requests are emailed to the configured inquiry inbox', function () {
    Mail::fake();
    config([
        'services.schoolms.inquiry_email' => 'admissions@schoolms.test',
        'mail.from.address' => 'no-reply@schoolms.test',
    ]);

    $this->from('/#demo')->post(route('inquiries.store'), [
        'kind' => 'demo',
        'name' => 'Amina Otieno',
        'email' => 'amina@example.test',
        'phone' => '+254711111111',
        'school' => 'Umoja Heights',
        'role' => 'Headteacher or principal',
        'message' => 'We want to see the academics and finance workflows.',
        'website' => '',
    ])->assertRedirect('/#demo');

    Mail::assertSent(PublicInquiryMail::class, function (PublicInquiryMail $mail) {
        return $mail->hasTo('admissions@schoolms.test')
            && $mail->hasReplyTo('amina@example.test')
            && str_contains($mail->render(), 'Umoja Heights')
            && str_contains($mail->render(), 'academics and finance');
    });
    expect(CentralRequest::where('type', 'demo')->where('status', 'pending')->exists())->toBeTrue();
});

test('contact inquiry requires a message and demo inquiry requires a school name', function () {
    $this->from('/#contact')->post(route('inquiries.store'), [
        'kind' => 'contact',
        'name' => 'Amina Otieno',
        'email' => 'amina@example.test',
        'website' => '',
    ])->assertSessionHasErrors('message');

    $this->from('/#demo')->post(route('inquiries.store'), [
        'kind' => 'demo',
        'name' => 'Amina Otieno',
        'email' => 'amina@example.test',
        'website' => '',
    ])->assertSessionHasErrors('school');
});

test('inquiry honeypot absorbs automated submissions without sending mail', function () {
    Mail::fake();

    $this->post(route('inquiries.store'), [
        'kind' => 'contact',
        'name' => 'Bot',
        'email' => 'bot@example.test',
        'message' => 'Automated message',
        'website' => 'https://spam.invalid',
    ])->assertRedirect('/');

    Mail::assertNotSent(PublicInquiryMail::class);
});
