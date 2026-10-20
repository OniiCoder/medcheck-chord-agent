<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\McpServerController;
use Illuminate\Support\Facades\Route;

// Live Operator Dashboard & Demo Control Panel
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// Streamable HTTP MCP Endpoint for Chord (supports GET health check and POST JSON-RPC 2.0)
Route::match(['get', 'post'], '/mcp', [McpServerController::class, 'handle'])->name('mcp.endpoint');

// Interactive Paystack Test Mode Checkout & Payment Outcome Simulator
Route::get('/paystack/checkout/{reference}', [DashboardController::class, 'checkout'])->name('paystack.checkout');
Route::post('/paystack/simulate/{reference}', [DashboardController::class, 'simulatePayment'])->name('paystack.simulate');
Route::get('/paystack/callback', [DashboardController::class, 'paystackCallback'])->name('paystack.callback');
