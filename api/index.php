<?php
declare(strict_types=1);
require __DIR__ . '/../app/services.php';
try {
    start_session();
    $method=$_SERVER['REQUEST_METHOD'];
    $path=trim($_GET['rota']??($_SERVER['PATH_INFO']??''),'/');
    $segments=explode('/',$path);
    $resource=$segments[0];
    $target=$segments[1]??null;
    if (count($segments)>2) throw new ApiError(404,'Rota não encontrada.');
    if (!in_array($method,['GET','POST','PUT','PATCH','DELETE'],true)) { header('Allow: GET, POST, PUT, PATCH, DELETE'); throw new ApiError(405,'Método não permitido.'); }
    if ($method!=='GET') csrf_check();
    if ($path==='sessao' && $method==='GET') respond(['usuario'=>current_user(),'csrf'=>$_SESSION['csrf']]);
    if ($path==='login' && $method==='POST') respond(login(json_body()));
    if ($path==='logout' && $method==='POST') {
        $_SESSION=[];
        $cookie=session_get_cookie_params();
        setcookie(session_name(),'', ['expires'=>time()-42000,'path'=>$cookie['path'],'secure'=>$cookie['secure'],'httponly'=>true,'samesite'=>'Lax']);
        session_destroy();
        respond(['mensagem'=>'Sessão encerrada.']);
    }
    $user=authorize($resource==='usuarios' ? 'admin' : ($method==='GET' ? 'read' : 'write'));
    if ($path==='calendario' && $method==='GET') respond(calendar_data($_GET['mes']??date('Y-m')));
    if ($path==='dias-fixos' && $method==='POST') respond(apply_fixed(json_body(),$user));
    if ($resource==='medicos') {
        $id=$target!==null ? int_field($target,'id_medico') : null;
        if ($method==='GET') {
            $rows=doctors($user['papel']==='viewer');
            if (!$id) respond($rows);
            foreach ($rows as $row) if ((int)$row['id_medico']===$id) respond($row);
            throw new ApiError(404,'Médico não encontrado.');
        }
        if ($method==='POST' && !$id) respond(save_doctor(json_body(),null),201);
        if (in_array($method,['PUT','PATCH'],true) && $id) respond(save_doctor(json_body(),$id));
        if ($method==='DELETE' && $id) { require_record('medico','id_medico',$id); query('UPDATE medico SET ativo=FALSE WHERE id_medico=?',[$id]); respond(['mensagem'=>'Médico desativado; histórico preservado.']); }
    }
    if ($resource==='agendamentos') {
        $id=$target!==null ? int_field($target,'id_agendamento') : null;
        if ($method==='GET') {
            if ($id) respond(require_record('agendamento','id_agendamento',$id));
            [$start,$end]=month_range($_GET['mes']??date('Y-m'));
            respond(appointments($start,$end));
        }
        if ($method==='POST' && !$id) respond(save_appointment(json_body(),null,$user),201);
        if (in_array($method,['PUT','PATCH'],true) && $id) respond(save_appointment(json_body(),$id,$user));
        if ($method==='DELETE' && $id) respond(save_appointment(['status'=>'CANCELADO'],$id,$user));
    }
    if ($resource==='escalas') {
        if ($target!==null) valid_date($target);
        if ($method==='GET') {
            if ($target) respond(schedules($target,(new DateTimeImmutable($target))->modify('+1 day')->format('Y-m-d'))[0]??null);
            [$start,$end]=month_range($_GET['mes']??date('Y-m'));
            respond(schedules($start,$end));
        }
        if ($method==='POST' && !$target) { $data=json_body(); respond(save_schedule($data,valid_date($data['data']??null),$user),201); }
        if (in_array($method,['PUT','PATCH'],true) && $target) respond(save_schedule(json_body(),$target,$user));
        if ($method==='DELETE' && $target) respond(save_schedule(['profissionais'=>[]],$target,$user));
    }
    if ($resource==='abreviacoes') {
        $id=$target!==null ? int_field($target,'id_abreviacao') : null;
        if ($method==='GET') respond($id ? require_record('abreviacao','id_abreviacao',$id) : query('SELECT * FROM abreviacao ORDER BY sigla')->fetchAll());
        if ($method==='POST' && !$id) respond(save_abbreviation(json_body(),null),201);
        if (in_array($method,['PUT','PATCH'],true) && $id) respond(save_abbreviation(json_body(),$id));
        if ($method==='DELETE' && $id) { require_record('abreviacao','id_abreviacao',$id); query('DELETE FROM abreviacao WHERE id_abreviacao=?',[$id]); respond(['mensagem'=>'Abreviação excluída.']); }
    }
    if ($resource==='usuarios') {
        $id=$target!==null ? int_field($target,'id_usuario') : null;
        if ($method==='GET') {
            if (!$id) respond(users());
            foreach (users() as $row) if ((int)$row['id_usuario']===$id) respond($row);
            throw new ApiError(404,'Usuário não encontrado.');
        }
        if ($method==='POST' && !$id) respond(save_user(json_body(),null,$user),201);
        if (in_array($method,['PUT','PATCH'],true) && $id) respond(save_user(json_body(),$id,$user));
        if ($method==='DELETE' && $id) respond(save_user(['ativo'=>false],$id,$user));
    }
    if ($resource==='feriados') {
        if ($target!==null) valid_date($target);
        if ($method==='GET' && !$target) { [$start,$end]=month_range($_GET['mes']??date('Y-m')); respond(query('SELECT * FROM feriado WHERE data>=? AND data<? ORDER BY data',[$start,$end])->fetchAll()); }
        if ($method==='PUT' && $target) {
            $data=json_body(); $description=text_field($data,'descricao',150);
            query('INSERT INTO feriado (data,descricao,id_usuario) VALUES (?,?,?) ON CONFLICT (data) DO UPDATE SET descricao=EXCLUDED.descricao,id_usuario=EXCLUDED.id_usuario',[$target,$description,$user['id_usuario']]);
            respond(['data'=>$target,'descricao'=>$description]);
        }
        if ($method==='DELETE' && $target) { query('DELETE FROM feriado WHERE data=?',[$target]); respond(['mensagem'=>'Feriado removido.']); }
    }
    if (!in_array($resource,['calendario','dias-fixos','medicos','usuarios','agendamentos','escalas','abreviacoes','feriados'],true)) throw new ApiError(404,'Rota não encontrada.');
    throw new ApiError(405,'Método não permitido nesta rota.');
} catch (ApiError $e) {
    http_response_code($e->status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['erro'=>$e->getMessage(),'campos'=>$e->fields],JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log($e->getMessage());
    $code=$e->getCode();
    http_response_code(in_array($code,['23505','23503'],true) ? 409 : 503);
    header('Content-Type: application/json; charset=utf-8');
    $message=match ($code) {
        '23505'=>'Já existe um registro com estes dados. Confira médico/data, escala/data, sigla ou e-mail.',
        '23503'=>'Este registro está em uso. Preserve o histórico ou desative o cadastro.',
        default=>'Não foi possível acessar o banco. Verifique a configuração e as migrações.',
    };
    echo json_encode(['erro'=>$message],JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['erro'=>'Não foi possível concluir a operação. Tente novamente.']);
}
