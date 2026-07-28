<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
class AuthController extends Controller {
 public function login(Request $r){
  $d=$r->validate(['username'=>'required|string','password'=>'required|string']);
  $u=User::with('role')->where('username',$d['username'])->where('status','Active')->first();
  if(!$u||!Hash::check($d['password'],$u->password_hash)) throw ValidationException::withMessages(['username'=>['Invalid credentials.']]);
  $r->session()->regenerate(); Auth::guard('web')->login($u); $u->update(['last_login'=>now()->format('Y-m-d H:i:s')]);
  return $this->me($r);
 }
 public function me(Request $r){$u=$r->user()?->load('role'); return response()->json(['user'=>$u,'permissions'=>$u?->role?->permissions??[],'landing_page'=>$u?->role?->landing_page??'dashboard']);}
 public function logout(Request $r){Auth::guard('web')->logout();$r->session()->invalidate();$r->session()->regenerateToken();return response()->noContent();}
}