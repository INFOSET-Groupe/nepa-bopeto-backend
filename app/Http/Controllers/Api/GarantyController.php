<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;

class GarantyController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $garanty = $user->garanty()->first();

        $last_payment = Payment::where('user_id', $user->id)
            ->where('type', 'garanty')
            ->orderBy('created_at', 'desc')
            ->get();

        return [
            'garanty' => $garanty->montant,
            'disponible' => $garanty->reste,
            'liste_payment' => $last_payment,
        ];

    }

    public function initiate(Request $request)
    {
        
    }

}
