<?php

namespace App\Http\Controllers\Admin;

use App\ClientPayment;
use App\CreditNotes;
use App\Currency;
use App\DataTables\Admin\InvoicesDataTable;
use App\Estimate;
use App\Helper\Reply;
use App\Http\Requests\Admin\Client\StoreShippingAddressRequest;
use App\Http\Requests\InvoiceFileStore;
use App\Http\Requests\Invoices\StoreInvoice;
use App\Invoice;
use App\InvoiceItems;
use App\InvoiceSetting;
use App\Notifications\PaymentReminder;
use App\OfflineInvoicePayment;
use App\Product;
use App\Project;
use App\Proposal;
use App\Scopes\CompanyScope;
use App\Tax;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Requests\Invoices\UpdateInvoice;
use App\ProjectMilestone;
use App\ProjectTimeLog;

class ManageAllInvoicesController extends AdminBaseController
{
    public function __construct()
    {
        parent::__construct();
        $this->pageTitle = 'app.menu.invoices';
        $this->pageIcon = 'ti-receipt';
        $this->middleware(function ($request, $next) {
            if (!in_array('invoices', $this->user->modules)) {
                abort(403);
            }
            return $next($request);
        });
    }

    public function index(InvoicesDataTable $dataTable)
    {
        $this->projects = Project::all();
        $this->clients = User::allClients();
        return $dataTable->render('admin.invoices.index', $this->data);
    }

    public function domPdfObjectForDownload($id)
    {
        $this->invoice = Invoice::findOrFail($id);
        $this->paidAmount = $this->invoice->getPaidAmount();
        $this->creditNote = 0;
        if ($this->invoice->credit_note) {
            $this->creditNote = CreditNotes::where('invoice_id', $id)
                ->select('cn_number')
                ->first();
        }

        if ($this->invoice->discount > 0) {
            if ($this->invoice->discount_type == 'percent') {
                $this->discount = (($this->invoice->discount / 100) * $this->invoice->sub_total);
            } else {
                $this->discount = $this->invoice->discount;
            }
        } else {
            $this->discount = 0;
        }

        $taxList = array();

        $items = InvoiceItems::whereNotNull('taxes')
            ->where('invoice_id', $this->invoice->id)
            ->get();

        foreach ($items as $item) {
            if ($this->invoice->discount > 0 && $this->invoice->discount_type == 'percent') {
                $item->amount = $item->amount - (($this->invoice->discount / 100) * $item->amount);
            }
            foreach (json_decode($item->taxes) as $tax) {
                $this->tax = InvoiceItems::taxbyid($tax)->first();
                if (!isset($taxList[$this->tax->tax_name . ': ' . $this->tax->rate_percent . '%'])) {
                    $taxList[$this->tax->tax_name . ': ' . $this->tax->rate_percent . '%'] = ($this->tax->rate_percent / 100) * $item->amount;
                } else {
                    $taxList[$this->tax->tax_name . ': ' . $this->tax->rate_percent . '%'] = $taxList[$this->tax->tax_name . ': ' . $this->tax->rate_percent . '%'] + (($this->tax->rate_percent / 100) * $item->amount);
                }
            }
        }

        $this->taxes = $taxList;

        $this->settings = $this->invoice->company;

        $this->invoiceSetting = InvoiceSetting::first();

        $pdf = app('dompdf.wrapper');
        $this->company = $this->invoice->company;
        $pdf->loadView('invoices.' . $this->invoiceSetting->template, $this->data);
        $filename = $this->invoice->invoice_number;

        return [
            'pdf' => $pdf,
            'fileName' => $filename
        ];
    }

    public function download($id)
    {

        $this->invoice = Invoice::findOrFail($id);

        // Download file uploaded
        if ($this->invoice->file != null) {
            return response()->download(storage_path('app/public/invoice-files') . '/' . $this->invoice->file);
        }

        $pdfOption = $this->domPdfObjectForDownload($id);
        $pdf = $pdfOption['pdf'];
        $filename = $pdfOption['fileName'];

        return $pdf->download($filename . '.pdf');
    }

    public function destroy($id)
    {
        $firstInvoice = Invoice::orderBy('id', 'desc')->first();
        if ($firstInvoice->id == $id) {
            if (CreditNotes::where('invoice_id', $id)->exists()) {
                CreditNotes::where('invoice_id', $id)->update(['invoice_id' => null]);
            }
            Invoice::destroy($id);
            return Reply::success(__('messages.invoiceDeleted'));
        } else {
            return Reply::error(__('messages.invoiceCanNotDeleted'));
        }
    }

    public function create()
    {
        $this->projects = Project::all();
        $this->currencies = Currency::all();
        $this->lastInvoice = Invoice::count() + 1;
        $this->invoiceSetting = InvoiceSetting::first();
        $this->zero = '';
        if (strlen($this->lastInvoice) < $this->invoiceSetting->invoice_digit) {
            for ($i = 0; $i < $this->invoiceSetting->invoice_digit - strlen($this->lastInvoice); $i++) {
                $this->zero = '0' . $this->zero;
            }
        }

        $this->taxes = Tax::all();
        $this->products = Product::all();
        $this->clients = User::allClients();
        if (request('type') == "timelog") {
            $this->startDate = Carbon::now($this->global->timezone)->subDays(7);
            $this->endDate = Carbon::now($this->global->timezone);
            return view('admin.invoices.create-invoice', $this->data);
        }
        return view('admin.invoices.create', $this->data);
    }

    public function store(StoreInvoice $request)
    {
        $items = $request->input('item_name');
        $itemsSummary = $request->input('item_summary');
        $cost_per_item = $request->input('cost_per_item');
        $quantity = $request->input('quantity');
        $amount = $request->input('amount');
        $tax = $request->input('taxes');

        foreach ($quantity as $qty) {
            if (!is_numeric($qty) && (intval($qty) < 1)) {
                return Reply::error(__('messages.quantityNumber'));
            }
        }

        foreach ($cost_per_item as $rate) {
            if (!is_numeric($rate)) {
                return Reply::error(__('messages.unitPriceNumber'));
            }
        }

        foreach ($amount as $amt) {
            if (!is_numeric($amt)) {
                return Reply::error(__('messages.amountNumber'));
            }
        }

        foreach ($items as $itm) {
            if (is_null($itm)) {
                return Reply::error(__('messages.itemBlank'));
            }
        }

        $invoice = new Invoice();
        $invoice->project_id = $request->project_id ?? null;
        $invoice->client_id = $request->project_id == '' && $request->has('client_id') ? $request->client_id : null;
        $invoice->invoice_number = Invoice::count() + 1;
        $invoice->issue_date = Carbon::createFromFormat($this->global->date_format, $request->issue_date)->format('Y-m-d');
        $invoice->due_date = Carbon::createFromFormat($this->global->date_format, $request->due_date)->format('Y-m-d');
        $invoice->sub_total = round($request->sub_total, 2);
        $invoice->discount = round($request->discount_value, 2);
        $invoice->discount_type = $request->discount_type;
        $invoice->total = round($request->total, 2);
        $invoice->currency_id = $request->currency_id;
        $invoice->recurring = $request->recurring_payment;
        $invoice->billing_frequency = $request->recurring_payment == 'yes' ? $request->billing_frequency : null;
        $invoice->billing_interval = $request->recurring_payment == 'yes' ? $request->billing_interval : null;
        $invoice->billing_cycle = $request->recurring_payment == 'yes' ? $request->billing_cycle : null;
        $invoice->note = $request->note;
        $invoice->show_shipping_address = $request->show_shipping_address;
        $invoice->save();

        if ($request->estimate_id) {
            $estimate = Estimate::findOrFail($request->estimate_id);
            $estimate->status = 'accepted';
            $estimate->save();
        }
        if ($request->proposal_id) {
            $proposal = Proposal::findOrFail($request->proposal_id);
            $proposal->invoice_convert = 1;
            $proposal->save();
        }


        if ($request->has('shipping_address')) {
            if ($invoice->project_id != null && $invoice->project_id != '') {
                $client = $invoice->project->clientdetails;
            } elseif ($invoice->client_id != null && $invoice->client_id != '') {
                $client = $invoice->clientdetails;
            }
            $client->shipping_address = $request->shipping_address;

            $client->save();
        }

        //set milestone paid if converted milestone to invoice
        if ($request->milestone_id != '') {
            $milestone = ProjectMilestone::findOrFail($request->milestone_id);
            $milestone->invoice_created = 1;
            $milestone->invoice_id = $invoice->id;
            $milestone->save();
        }

        //log search
        $this->logSearchEntry($invoice->id, 'Invoice ' . $invoice->invoice_number, 'admin.all-invoices.show', 'invoice');

        return Reply::redirect(route('admin.all-invoices.index'), __('messages.invoiceCreated'));
    }

    public function remindForPayment($taskID)
    {
        $invoice = Invoice::with(['project', 'project.client'])->findOrFail($taskID);
        // Send  reminder notification to user

        $userClient = User::findOrFail($invoice->project ? $invoice->project->client->user_id : $invoice->client_id);
        $notifyUser = $userClient;
        $notifyUser->notify(new PaymentReminder($invoice));

        return Reply::success('messages.reminderMailSuccess');
    }

    public function edit($id)
    {
        $this->invoice = Invoice::findOrFail($id);
        $this->projects = Project::all();
        $this->currencies = Currency::all();

        if ($this->invoice->status == 'paid') {
            abort(403);
        }
        $this->taxes = Tax::all();
        $this->products = Product::all();
        $this->clients = User::allClients();
        if ($this->invoice->project_id != '') {
            $companyName = Project::where('id', $this->invoice->project_id)->with('clientdetails')->first();
            $this->companyName = $companyName->clientdetails ? $companyName->clientdetails->company_name : '';
            $this->clientId = $companyName->clientdetails ? $companyName->clientdetails->user_id : '';
        }

        return view('admin.invoices.edit', $this->data);
    }

    public function update(UpdateInvoice $request, $id)
    {
        $items = $request->input('item_name');
        $itemsSummary = $request->input('item_summary');
        $cost_per_item = $request->input('cost_per_item');
        $quantity = $request->input('quantity');
        $amount = $request->input('amount');
        $tax = $request->input('taxes');

        foreach ($quantity as $qty) {
            if (!is_numeric($qty) && $qty < 1) {
                return Reply::error(__('messages.quantityNumber'));
            }
        }

        foreach ($cost_per_item as $rate) {
            if (!is_numeric($rate)) {
                return Reply::error(__('messages.unitPriceNumber'));
            }
        }

        foreach ($amount as $amt) {
            if (!is_numeric($amt)) {
                return Reply::error(__('messages.amountNumber'));
            }
        }

        foreach ($items as $itm) {
            if (is_null($itm)) {
                return Reply::error(__('messages.itemBlank'));
            }
        }

        $invoice = Invoice::findOrFail($id);

        if ($invoice->status == 'paid') {
            return Reply::error(__('messages.invalidRequest'));
        }

        $invoice->project_id            = $request->project_id ?? null;
        $invoice->client_id             = $request->project_id == '' && $request->has('client_id') ? $request->client_id : null;
        $invoice->issue_date            = Carbon::createFromFormat($this->global->date_format, $request->issue_date)->format('Y-m-d');
        $invoice->due_date              = Carbon::createFromFormat($this->global->date_format, $request->due_date)->format('Y-m-d');
        $invoice->sub_total             = round($request->sub_total, 2);
        $invoice->discount              = round($request->discount_value, 2);
        $invoice->discount_type         = $request->discount_type;
        $invoice->total                 = round($request->total, 2);
        $invoice->currency_id           = $request->currency_id;
        $invoice->status                = $request->status;
        $invoice->recurring             = $request->recurring_payment;
        $invoice->billing_frequency     = $request->recurring_payment == 'yes' ? $request->billing_frequency : null;
        $invoice->billing_interval      = $request->recurring_payment == 'yes' ? $request->billing_interval : null;
        $invoice->billing_cycle         = $request->recurring_payment == 'yes' ? $request->billing_cycle : null;
        $invoice->note                  = $request->note;
        $invoice->show_shipping_address = $request->show_shipping_address;
        $invoice->save();

        // delete and create new
        InvoiceItems::where('invoice_id', $invoice->id)->delete();

        foreach ($items as $key => $item) :
            InvoiceItems::create(
                [
                    'invoice_id' => $invoice->id,
                    'item_name' => $item,
                    'item_summary' => $itemsSummary[$key],
                    'type' => 'item',
                    'quantity' => $quantity[$key],
                    'unit_price' => round($cost_per_item[$key], 2),
                    'amount' => round($amount[$key], 2),
                    'taxes' => $tax ? array_key_exists($key, $tax) ? json_encode($tax[$key]) : null : null
                ]
            );
        endforeach;

        if ($request->has('shipping_address')) {
            if ($invoice->project_id != null && $invoice->project_id != '') {
                $client = $invoice->project->clientdetails;
            } elseif ($invoice->client_id != null && $invoice->client_id != '') {
                $client = $invoice->clientdetails;
            }
            $client->shipping_address = $request->shipping_address;

            $client->save();
        }

        return Reply::redirect(route('admin.all-invoices.index'), __('messages.invoiceUpdated'));
    }

    public function show($id)
    {
        $this->invoice = Invoice::findOrFail($id);
        $this->paidAmount = $this->invoice->getPaidAmount();


        if ($this->invoice->discount > 0) {
            if ($this->invoice->discount_type == 'percent') {
                $this->discount = (($this->invoice->discount / 100) * $this->invoice->sub_total);
            } else {
                $this->discount = $this->invoice->discount;
            }
        } else {
            $this->discount = 0;
        }

        $taxList = array();

        $items = InvoiceItems::whereNotNull('taxes')
            ->where('invoice_id', $this->invoice->id)
            ->get();
        foreach ($items as $item) {
            if ($this->invoice->discount > 0 && $this->invoice->discount_type == 'percent') {
                $item->amount = $item->amount - (($this->invoice->discount / 100) * $item->amount);
            }
            foreach (json_decode($item->taxes) as $tax) {
                $this->tax = InvoiceItems::taxbyid($tax)->first();
                if (!isset($taxList[$this->tax->tax_name . ': ' . $this->tax->rate_percent . '%'])) {
                    $taxList[$this->tax->tax_name . ': ' . $this->tax->rate_percent . '%'] = ($this->tax->rate_percent / 100) * $item->amount;
                } else {
                    $taxList[$this->tax->tax_name . ': ' . $this->tax->rate_percent . '%'] = $taxList[$this->tax->tax_name . ': ' . $this->tax->rate_percent . '%'] + (($this->tax->rate_percent / 100) * $item->amount);
                }
            }
        }

        $this->taxes = $taxList;

        $this->settings = $this->company;
        $this->invoiceSetting = InvoiceSetting::first();
        return view('admin.invoices.show', $this->data);
    }

    public function appliedCredits(Request $request, $id)
    {
        $this->invoice = Invoice::findOrFail($id);

        $this->creditNotes = $this->invoice->credit_notes()->orderBy('date', 'DESC')->get();

        return view('admin.invoices.applied_credits', $this->data);
    }

    public function deleteAppliedCredit(Request $request, $id)
    {
        $this->invoice = Invoice::findOrFail($request->invoice_id);

        // delete from credit_notes_invoice_table
        $invoiceCreditNote = $this->invoice->credit_notes()->wherePivot('id', $id);
        $creditNote = $invoiceCreditNote->first();
        $invoiceCreditNote->detach();

        // change invoice status
        $this->invoice->status = 'partial';
        if ($this->invoice->amountPaid() == $this->invoice->total) {
            $this->invoice->status = 'paid';
        }
        if ($this->invoice->amountPaid() == 0) {
            $this->invoice->status = 'unpaid';
        }
        $this->invoice->save();

        // change credit note status
        if ($creditNote->status == 'closed') {
            $creditNote->status = 'open';
            $creditNote->save();
        }

        $this->creditNotes = $this->invoice->credit_notes()->orderBy('date', 'DESC')->get();
        if ($this->creditNotes->count() > 0) {
            $view = view('admin.invoices.applied_credits', $this->data)->render();

            return Reply::successWithData(__('messages.creditedInvoiceDeletedSuccessfully'), ['view' => $view]);
        }
        return Reply::redirect(route('admin.all-invoices.show', [$this->invoice->id]), __('messages.creditedInvoiceDeletedSuccessfully'));
    }

    public function convertEstimate($id)
    {
        $this->estimateId = $id;
        $this->invoice = Estimate::with('items')->findOrFail($id);
        $this->lastInvoice = Invoice::count() + 1;
        $this->invoiceSetting = InvoiceSetting::first();
        $this->projects = Project::all();
        $this->currencies = Currency::all();
        $this->taxes = Tax::all();
        $this->products = Product::all();
        $this->zero = '';

        if (strlen($this->lastInvoice) < $this->invoiceSetting->invoice_digit) {
            for ($i = 0; $i < $this->invoiceSetting->invoice_digit - strlen($this->lastInvoice); $i++) {
                $this->zero = '0' . $this->zero;
            }
        }
        //        foreach ($this->invoice->items as $items)

        $discount = $this->invoice->items->filter(function ($value, $key) {
            return $value->type == 'discount';
        });

        $tax = $this->invoice->items->filter(function ($value, $key) {
            return $value->type == 'tax';
        });

        $this->totalTax = $tax->sum('amount');
        $this->totalDiscount = $discount->sum('amount');

        return view('admin.invoices.convert_estimate', $this->data);
    }

    public function convertProposal($id)
    {
        $this->invoice = Proposal::findOrFail($id);
        $this->lastInvoice = Invoice::withoutGlobalScope(CompanyScope::class)->orderBy('id', 'desc')->first();
        $this->invoiceSetting = InvoiceSetting::first();
        $this->projects = Project::all();
        $this->currencies = Currency::all();
        return view('admin.invoices.convert_estimate', $this->data);
    }

    public function addItems(Request $request)
    {
        $this->items = Product::with('tax')->find($request->id);
        $this->taxes = Tax::all();
        $view = view('admin.invoices.add-item', $this->data)->render();
        return Reply::dataOnly(['status' => 'success', 'view' => $view]);
    }


    public function paymentDetail($invoiceID)
    {
        $this->invoice = Invoice::with('payment', 'currency')->findOrFail($invoiceID);

        return View::make('admin.invoices.payment-detail', $this->data);
    }

    /**
     * @param InvoiceFileStore $request
     * @return array
     */
    public function storeFile(InvoiceFileStore $request)
    {
        $invoiceId = $request->invoice_id;
        $file = $request->file('file');

        $newName = $file->hashName(); // setting hashName name
        // Getting invoice data
        $invoice = Invoice::find($invoiceId);

        if ($invoice != null) {

            if ($invoice->file != null) {
                unlink(storage_path('app/public/invoice-files') . '/' . $invoice->file);
            }

            $file->move(storage_path('app/public/invoice-files'), $newName);

            $invoice->file = $newName;
            $invoice->file_original_name = $file->getClientOriginalName(); // Getting uploading file name;

            $invoice->save();

            return Reply::success(__('messages.fileUploadedSuccessfully'));
        }

        return Reply::error(__('messages.fileUploadIssue'));
    }

    public function checkShippingAddress()
    {
        if (request()->has('clientId')) {
            $user = User::findOrFail(request()->clientId);
            if (request()->showShipping == 'yes' && (is_null($user->client_details->shipping_address) || $user->client_details->shipping_address === '')) {
                $view = view('admin.invoices.show_shipping_address_input')->render();
                return Reply::dataOnly(['view' => $view]);
            } else {
                return Reply::dataOnly(['show' => 'false']);
            }
        } else {
            return Reply::dataOnly(['switch' => 'off']);
        }
    }

    public function toggleShippingAddress(Invoice $invoice)
    {
        if ($invoice->show_shipping_address === 'yes') {
            $invoice->show_shipping_address = 'no';
        } else {
            $invoice->show_shipping_address = 'yes';
        }

        $invoice->save();

        return Reply::success(__('messages.updatedSuccessfully'));
    }

    public function shippingAddressModal(Invoice $invoice)
    {
        $clientId = $invoice->clientdetails ? $invoice->clientdetails->user_id : $invoice->project->clientdetails->user_id;

        return view('sections.add_shipping_address', ['clientId' => $clientId]);
    }

    public function addShippingAddress(StoreShippingAddressRequest $request, User $user)
    {
        $user->client_details->shipping_address = $request->shipping_address;

        $user->client_details->save();

        return Reply::success(__('messages.addedSuccessfully'));
    }

    /**
     * @param Request $request
     * @return array
     */
    public function destroyFile(Request $request)
    {
        $invoiceId = $request->invoice_id;

        $invoice = Invoice::find($invoiceId);

        if ($invoice != null) {

            if ($invoice->file != null) {
                unlink(storage_path('app/public/invoice-files') . '/' . $invoice->file);
            }

            $invoice->file = null;
            $invoice->file_original_name = null;

            $invoice->save();
        }

        return Reply::success(__('messages.fileDeleted'));
    }

    /**
     * @param $startDate
     * @param $endDate
     * @param $status
     * @param $projectID
     */
    public function export($startDate, $endDate, $status, $projectID)
    {

        $invoices = Invoice::with(['project:id,project_name', 'currency:id,currency_symbol']);

        if ($startDate !== null && $startDate != 'null' && $startDate != '') {
            $invoices = $invoices->where(DB::raw('DATE(invoices.`issue_date`)'), '>=', $startDate);
        }

        if ($endDate !== null && $endDate != 'null' && $endDate != '') {
            $invoices = $invoices->where(DB::raw('DATE(invoices.`issue_date`)'), '<=', $endDate);
        }

        if ($status != 'all' && !is_null($status)) {
            $invoices = $invoices->where('invoices.status', '=', $status);
        }

        if ($projectID != 'all' && !is_null($projectID)) {
            $invoices = $invoices->where('invoices.project_id', '=', $projectID);
        }

        $invoices = $invoices->orderBy('id', 'desc')
            ->get()
            ->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'project_name' => $invoice->project->project_name,
                    'status' => $invoice->status,
                    'total' => $invoice->currency->currency_symbol . $invoice->total,
                    'amount_used' => $invoice->currency->currency_symbol . $invoice->amountPaid(),
                    'amount_remaining' => $invoice->currency->currency_symbol . $invoice->amountDue(),
                    'issue_date' => $invoice->issue_date ? $invoice->issue_date->format($this->global->date_format) : ''
                ];
            })->toArray();

        // Define the Excel spreadsheet headers
        $headerRow = ['ID', 'Invoice #', 'Project Name', 'Status', 'Total Amount', 'Amount Paid', 'Amount Due', 'Invoice Date'];

        array_unshift($invoices, $headerRow);

        // Generate and return the spreadsheet
        Excel::create('invoice', function ($excel) use ($invoices) {

            // Set the spreadsheet title, creator, and description
            $excel->setTitle('Invoice');
            $excel->setCreator('Worksuite')->setCompany($this->companyName);
            $excel->setDescription('invoice file');

            // Build the spreadsheet, passing in the payments array
            $excel->sheet('sheet1', function ($sheet) use ($invoices) {
                $sheet->fromArray($invoices, null, 'A1', false, false);

                $sheet->row(1, function ($row) {

                    // call row manipulation methods
                    $row->setFont(array(
                        'bold'       =>  true
                    ));
                });
            });
        })->download('xlsx');
    }

    public function getClient($projectID)
    {
        $companyName = Project::with('client')->find($projectID);
        return $companyName->client->company_name;
    }

    public function getClientOrCompanyName($projectID = '')
    {
        $this->projectID = $projectID;

        if ($projectID == '') {
            $this->clients = User::allClients();
        } else {
            $companyName = Project::where('id', $projectID)->with('clientdetails')->first();
            $this->companyName = $companyName->clientdetails ? $companyName->clientdetails->company_name : '';
            $this->clientId = $companyName->clientdetails ? $companyName->clientdetails->user_id : '';
        }

        $list = view('admin.invoices.client_or_company_name', $this->data)->render();
        return Reply::dataOnly(['html' => $list]);
    }


    public function convertMilestone($id)
    {
        $this->invoice = ProjectMilestone::findOrFail($id);
        $this->lastInvoice = Invoice::orderBy('id', 'desc')->first();
        $this->invoiceSetting = InvoiceSetting::first();
        $this->lastInvoice = Invoice::count() + 1;
        $this->projects = Project::all();
        $this->currencies = Currency::all();
        $this->taxes = Tax::all();
        $this->products = Product::all();
        $this->zero = '';
        if (strlen($this->lastInvoice) < $this->invoiceSetting->invoice_digit) {
            for ($i = 0; $i < $this->invoiceSetting->invoice_digit - strlen($this->lastInvoice); $i++) {
                $this->zero = '0' . $this->zero;
            }
        }
        return view('admin.invoices.convert_milestone', $this->data);
    }

    public function verifyOfflinePayment($id)
    {
        $this->invoice = Invoice::with('offline_invoice_payment', 'offline_invoice_payment.payment_method')->findOrFail($id);
        return view('admin.invoices.verify-payment-detail', $this->data);
    }

    public function verifyPayment(Request $request, $id)
    {
        $offlineRequest = OfflineInvoicePayment::findOrFail($id);
        $invoice = Invoice::findOrFail($offlineRequest->invoice_id);

        // Change the status of payment request
        $offlineRequest->status = 'approve';
        $offlineRequest->save();

        // change the status of payment to paid
        $payment = ClientPayment::where('invoice_id', $invoice->id)->where('status', 'pending')->first();
        $payment->status = 'complete';
        $payment->save();

        //Change the status of invoice
        $invoice->status = 'paid';
        $invoice->save();

        return Reply::success('Successfully verified');
    }

    public function rejectPayment(Request $request, $id)
    {
        $offlineRequest = OfflineInvoicePayment::findOrFail($id);
        $invoice = Invoice::findOrFail($offlineRequest->invoice_id);

        $offlineRequest->status = 'reject';
        $offlineRequest->save();

        //Change the status of invoice
        $invoice->status = 'unpaid';
        $invoice->save();

        return Reply::success('Successfully rejected');
    }

    /**
     * @param Request $request
     * @return array
     */
    public function cancelStatus(Request $request)
    {
        $invoice = Invoice::find($request->invoiceID);
        $invoice->status = 'canceled'; // update status as canceled
        $invoice->save();

        return Reply::success(__('messages.invoiceUpdated'));
    }

    public function fetchTimelogs(Request $request)
    {
        $this->taxes = Tax::all();
        $projectId = $request->projectId;
        $timelogFrom = Carbon::createFromFormat($this->global->date_format, $request->timelogFrom)->format('Y-m-d');
        $timelogTo = Carbon::createFromFormat($this->global->date_format, $request->timelogTo)->format('Y-m-d');
        $this->timelogs = ProjectTimeLog::with('task')
            ->leftJoin('tasks', 'tasks.id', '=', 'project_time_logs.task_id')
            ->groupBy('project_time_logs.task_id')
            ->where('project_time_logs.project_id', $projectId)
            ->where('project_time_logs.earnings', '>', 0)
            ->where(
                function ($query) {
                    $query->where('tasks.billable', 1)
                        ->orWhereNull('tasks.billable');
                }
            )
            ->whereDate('project_time_logs.start_time', '>=', $timelogFrom)
            ->whereDate('project_time_logs.end_time', '<=', $timelogTo)
            ->selectRaw('project_time_logs.id, project_time_logs.task_id, sum(project_time_logs.earnings) as sum')
            ->get();
        $html = view('admin.invoices.timelog-item', $this->data)->render();
        return Reply::dataOnly(['html' => $html]);
    }
}
