<?php

namespace App\Notifications;

use App\Estimate;
use App\Http\Controllers\Admin\ManageEstimatesController;
use Illuminate\Bus\Queueable;
use App\Traits\SmtpSettings;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class NewEstimate extends Notification implements ShouldQueue
{
    use Queueable, SmtpSettings;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    private $estimate;
    public function __construct(Estimate $estimate)
    {
        $this->estimate = $estimate;
        $this->setMailConfigs();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $url = url(route('front.estimate.show', md5($this->estimate->id)));

        // For Sending pdf to email
        // $invoiceController = new ManageEstimatesController();
        // $pdfOption = $invoiceController->domPdfObjectForDownload($this->estimate->id);
        // $pdf = $pdfOption['pdf'];
        // $filename = $pdfOption['fileName'];

        // Config::set('app.name', $this->estimate->company->company_name);

        return (new MailMessage)
            ->subject(__('email.estimate.subject'))
            ->greeting(__('email.hello').' '.ucwords($notifiable->name).'!')
            ->line(__('email.estimate.text'))
            ->action(__('email.estimate.approveReject'), getDomainSpecificUrl($url, $notifiable->company))
            ->line(__('email.thankyouNote'));
            // ->attachData($pdf->output(), $filename.'.pdf');
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            'id' => $this->estimate->id,
            'total' => $this->estimate->total,
        ];
    }
}