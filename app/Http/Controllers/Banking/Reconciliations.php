<?php

namespace App\Http\Controllers\Banking;

use App\Abstracts\Http\Controller;
use App\Http\Requests\Banking\Reconciliation as Request;
use App\Http\Requests\Banking\ReconciliationCalculate as CalculateRequest;
use App\Jobs\Banking\CreateReconciliation;
use App\Jobs\Banking\DeleteReconciliation;
use App\Jobs\Banking\UpdateReconciliation;
use App\Models\Banking\Account;
use App\Models\Banking\Reconciliation;
use App\Models\Banking\Transaction;
use Date;

class Reconciliations extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        $reconciliations = Reconciliation::with('account')->orderBy('ended_at', 'desc')->collect();

        $accounts = collect(Account::enabled()->orderBy('name')->pluck('name', 'id'));

        return $this->response('banking.reconciliations.index', compact('reconciliations', 'accounts'));
    }

    /**
     * Show the form for viewing the specified resource.
     *
     * @return Response
     */
    public function show()
    {
        return redirect()->route('reconciliations.index');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        $accounts = Account::enabled()->pluck('name', 'id');

        $account_id = request('account_id', setting('default.account'));
        $started_at = request('started_at', Date::now()->firstOfMonth()->toDateString());
        $ended_at = request('ended_at', Date::now()->endOfMonth()->toDateString());

        $account = Account::find($account_id);

        $currency = $account->currency;

        $transactions = $this->getTransactions($account, $ended_at);

        $opening_balance = $this->getOpeningBalance($account, $started_at);

        return view('banking.reconciliations.create', compact('accounts', 'account', 'currency', 'opening_balance', 'transactions'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  Request  $request
     *
     * @return Response
     */
    public function store(Request $request)
    {
        $response = $this->ajaxDispatch(new CreateReconciliation($request));

        if ($response['success']) {
            $response['redirect'] = route('reconciliations.index');

            $message = trans('messages.success.added', ['type' => trans_choice('general.reconciliations', 1)]);

            flash($message)->success();
        } else {
            $response['redirect'] = route('reconciliations.create');

            $message = $response['message'];

            flash($message)->error()->important();
        }

        return response()->json($response);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  Reconciliation  $reconciliation
     *
     * @return Response
     */
    public function edit(Reconciliation $reconciliation)
    {
        $account = $reconciliation->account;

        $currency = $account->currency;

        $transactions = $this->getTransactions($account, $reconciliation->ended_at, $reconciliation->updated_at, $reconciliation->reconciled);

        $opening_balance = $this->getOpeningBalance($account, $reconciliation->started_at);

        return view('banking.reconciliations.edit', compact('reconciliation', 'account', 'currency', 'opening_balance', 'transactions'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  Reconciliation $reconciliation
     * @param  Request $request
     *
     * @return Response
     */
    public function update(Reconciliation $reconciliation, Request $request)
    {
        $response = $this->ajaxDispatch(new UpdateReconciliation($reconciliation, $request));

        if ($response['success']) {
            $response['redirect'] = route('reconciliations.index');

            $message = trans('messages.success.updated', ['type' => trans_choice('general.reconciliations', 1)]);

            flash($message)->success();
        } else {
            $response['redirect'] = route('reconciliations.edit', $reconciliation->id);

            $message = $response['message'];

            flash($message)->error()->important();
        }

        return response()->json($response);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  Reconciliation $reconciliation
     *
     * @return Response
     */
    public function destroy(Reconciliation $reconciliation)
    {
        $response = $this->ajaxDispatch(new DeleteReconciliation($reconciliation));

        $response['redirect'] = route('reconciliations.index');

        if ($response['success']) {
            $message = trans('messages.success.deleted', ['type' => trans_choice('general.reconciliations', 1)]);

            flash($message)->success();
        } else {
            $message = $response['message'];

            flash($message)->error()->important();
        }

        return response()->json($response);
    }

    /**
     * Add transactions array.
     *
     * @param $account
     * @param $ended_at
     * @param $updated_at
     * @param bool $reconciled
     *
     * @return mixed
     */
    protected function getTransactions($account, $ended_at, $updated_at = null, bool $reconciled = false)
    {
        $ended = explode(' ', $ended_at)[0] . ' 23:59:59';
        $updated_after = Date::parse($updated_at)->addSeconds(30)->toDateTimeString();

        if ($reconciled) {
            $transactions = Transaction::with('account', 'contact')
                ->where('account_id', $account->id)
                ->whereBetween('updated_at', [$updated_at, $updated_after])
                ->where('reconciled', '=', $reconciled)
                ->get();
        } else {
            $transactions = Transaction::with('account', 'contact')
                ->where('account_id', $account->id)
                ->whereDate('paid_at', '<', $ended)
                ->where('reconciled', '=', $reconciled)
                ->get();
        }

        return collect($transactions)->sortBy('paid_at');
    }

    /**
     * Get the opening balance
     *
     * @param $account
     * @param $started_at
     *
     * @return string
     */
    public function getOpeningBalance($account, $started_at)
    {
        // Opening Balance
        $total = $account->opening_balance;

        // Sum income transactions
        $transactions = $account->income_transactions()->whereDate('paid_at', '<', $started_at)->where('reconciled', '=', 1)->get();
        foreach ($transactions as $item) {
            $total += $item->amount;
        }

        // Subtract expense transactions
        $transactions = $account->expense_transactions()->whereDate('paid_at', '<', $started_at)->where('reconciled', '=', 1)->get();
        foreach ($transactions as $item) {
            $total -= $item->amount;
        }

        return $total;
    }

    public function calculate(CalculateRequest $request)
    {
        $currency_code = $request['currency_code'];
        $closing_balance = $request['closing_balance'];

        $json = new \stdClass();

        $cleared_amount = $difference = $income_total = $expense_total = 0;

        if ($transactions = $request['transactions']) {
            $opening_balance = $request['opening_balance'];

            foreach ($transactions as $key => $value) {
                $k = explode('_', $key);

                if ($k[1] == 'income') {
                    $income_total += $value;
                } else {
                    $expense_total += $value;
                }
            }

            $cleared_amount = $opening_balance + ($income_total - $expense_total);
        }

        $difference = $closing_balance - $cleared_amount;

        $json->closing_balance = money($closing_balance, $currency_code, true)->format();
        $json->cleared_amount = money($cleared_amount, $currency_code, true)->format();
        $json->difference = money($difference, $currency_code, true)->format();
        $json->difference_raw = (int) $difference;

        return response()->json($json);
    }
}
