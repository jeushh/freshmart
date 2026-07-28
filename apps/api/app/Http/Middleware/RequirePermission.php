<?php
namespace App\Http\Middleware;
use Closure; use Illuminate\Http\Request;
class RequirePermission {public function handle(Request $request,Closure $next,string $permission){$user=$request->user();abort_unless($user,401);$perms=$user->role?->permissions??[];$allowed=array_filter(explode('|',$permission));abort_unless(in_array('*',$perms,true)||count(array_intersect($allowed,$perms))>0,403,'Permission denied.');return $next($request);}}