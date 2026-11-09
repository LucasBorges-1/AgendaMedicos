<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function doctors(bool $onlyActive = false): array {
    $rows = query("SELECT m.*, COALESCE(json_agg(d.dia_semana ORDER BY d.dia_semana) FILTER (WHERE d.id IS NOT NULL), '[]') AS dias_semana
        FROM medico m LEFT JOIN medico_dia_semana d ON d.id_medico=m.id_medico " . ($onlyActive ? 'WHERE m.ativo = TRUE ' : '') . 'GROUP BY m.id_medico ORDER BY m.nome, m.id_medico')->fetchAll();
    foreach ($rows as &$row) $row['dias_semana'] = json_decode($row['dias_semana'], true);
    return $rows;
}

function require_record(string $table, string $column, int $id, bool $active = false): array {
    // Identificadores vêm exclusivamente de literais internos, nunca da requisição.
    $row = query("SELECT * FROM $table WHERE $column = ?", [$id])->fetch();
    if (!$row) throw new ApiError(404, 'Registro não encontrado.');
    if ($active && !$row['ativo']) throw new ApiError(422, 'Este cadastro está inativo.');
    return $row;
}

function save_doctor(array $data, ?int $id): array {
    return transaction(function () use ($data, $id): array {
        $old = $id ? require_record('medico','id_medico',$id) : [];
        $data = array_replace($old, $data);
        $name = text_field($data,'nome',150);
        $type = $data['tipo'] ?? 'variado';
        if (!in_array($type,['fixo','variado'],true)) throw new ApiError(422,'Tipo deve ser fixo ou variado.');
        $active = bool_field($data,'ativo');
        $days = $data['dias_semana'] ?? ($id ? array_column(query('SELECT dia_semana FROM medico_dia_semana WHERE id_medico=?',[$id])->fetchAll(),'dia_semana') : []);
        if (!is_array($days) || !array_is_list($days) || count($days)>7) throw new ApiError(422,'Dias da semana inválidos.');
        foreach ($days as $day) if (!is_int($day) || $day<0 || $day>6) throw new ApiError(422,'Os dias devem ser números de 0 (domingo) a 6 (sábado).');
        $days = $type === 'fixo' ? array_values(array_unique($days)) : [];
        if ($type === 'fixo' && !$days) throw new ApiError(422,'Selecione pelo menos um dia fixo.');
        if ($id) query('UPDATE medico SET nome=?,tipo=?,ativo=? WHERE id_medico=?',[$name,$type,$active,$id]);
        else $id=(int)query('INSERT INTO medico (nome,tipo,ativo) VALUES (?,?,?) RETURNING id_medico',[$name,$type,$active])->fetchColumn();
        query('DELETE FROM medico_dia_semana WHERE id_medico=?',[$id]);
        foreach ($days as $day) query('INSERT INTO medico_dia_semana (id_medico,dia_semana) VALUES (?,?)',[$id,$day]);
        $result=require_record('medico','id_medico',$id);
        $result['dias_semana']=$days;
        return $result;
    });
}

function appointments(string $start, string $end): array {
    return query('SELECT a.*, m.nome AS medico_nome, m.ativo AS medico_ativo, b.sigla, b.descricao AS abreviacao_descricao, u.nome AS usuario_nome
        FROM agendamento a JOIN medico m ON m.id_medico=a.id_medico JOIN usuario u ON u.id_usuario=a.id_usuario
        LEFT JOIN abreviacao b ON b.id_abreviacao=a.id_abreviacao WHERE a.data >= ? AND a.data < ? ORDER BY a.data,m.nome,a.id_agendamento',[$start,$end])->fetchAll();
}

function schedules(string $start, string $end): array {
    $rows=query("SELECT e.*, COALESCE(json_agg(json_build_object('id',p.id,'nome_profissional',p.nome_profissional,'ordem',p.ordem) ORDER BY p.ordem,p.id) FILTER (WHERE p.id IS NOT NULL),'[]') AS profissionais
        FROM escala e LEFT JOIN escala_profissional p ON p.id_escala=e.id_escala WHERE e.data >= ? AND e.data < ? GROUP BY e.id_escala ORDER BY e.data",[$start,$end])->fetchAll();
    foreach ($rows as &$row) $row['profissionais']=json_decode($row['profissionais'],true);
    return $rows;
}

function calendar_data(string $month): array {
    [$start,$end]=month_range($month);
    // Uma ida ao banco por mês, importante quando a função e o Supabase são remotos.
    $json = query("WITH periodo AS (SELECT CAST(? AS date) AS inicio, CAST(? AS date) AS fim)
        SELECT json_build_object(
            'mes', CAST(? AS text),
            'agendamentos', COALESCE((SELECT json_agg(row_to_json(a)) FROM (
                SELECT a.*,m.nome AS medico_nome,m.ativo AS medico_ativo,b.sigla,b.descricao AS abreviacao_descricao,u.nome AS usuario_nome
                FROM agendamento a JOIN medico m USING (id_medico) JOIN usuario u USING (id_usuario)
                LEFT JOIN abreviacao b USING (id_abreviacao), periodo
                WHERE a.data>=inicio AND a.data<fim ORDER BY a.data,m.nome,a.id_agendamento
            ) a),'[]'::json),
            'escalas', COALESCE((SELECT json_agg(row_to_json(e)) FROM (
                SELECT e.*,COALESCE((SELECT json_agg(json_build_object('id',p.id,'nome_profissional',p.nome_profissional,'ordem',p.ordem) ORDER BY p.ordem,p.id)
                FROM escala_profissional p WHERE p.id_escala=e.id_escala),'[]'::json) AS profissionais
                FROM escala e,periodo WHERE e.data>=inicio AND e.data<fim ORDER BY e.data
            ) e),'[]'::json),
            'feriados', COALESCE((SELECT json_agg(row_to_json(f)) FROM (
                SELECT f.* FROM feriado f,periodo WHERE f.data>=inicio AND f.data<fim ORDER BY f.data
            ) f),'[]'::json),
            'medicos', COALESCE((SELECT json_agg(row_to_json(m)) FROM (
                SELECT m.*,COALESCE((SELECT json_agg(d.dia_semana ORDER BY d.dia_semana) FROM medico_dia_semana d WHERE d.id_medico=m.id_medico),'[]'::json) AS dias_semana
                FROM medico m ORDER BY m.nome,m.id_medico
            ) m),'[]'::json),
            'abreviacoes', COALESCE((SELECT json_agg(row_to_json(b)) FROM (SELECT * FROM abreviacao ORDER BY sigla) b),'[]'::json)
        )", [$start,$end,$month])->fetchColumn();
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

function save_appointment(array $data, ?int $id, array $user): array {
    return transaction(function () use ($data,$id,$user): array {
        $old=$id ? require_record('agendamento','id_agendamento',$id) : [];
        $data=array_replace($old,$data);
        $doctor=int_field($data['id_medico']??null,'id_medico');
        // Permite atualizar o status de um agendamento histórico de médico inativo.
        require_record('medico','id_medico',$doctor, !$id || $doctor !== (int)$old['id_medico']);
        $date=valid_date($data['data']??null);
        $tag=isset($data['id_abreviacao']) ? int_field($data['id_abreviacao'],'id_abreviacao') : null;
        if ($tag) require_record('abreviacao','id_abreviacao',$tag);
        $note=text_field($data,'observacao',3000,false);
        $status=$data['status']??'AGENDADO';
        if (!in_array($status,['AGENDADO','CONFIRMADO','CANCELADO','CONCLUIDO'],true)) throw new ApiError(422,'Status inválido.');
        if ($id) query('UPDATE agendamento SET id_medico=?,id_abreviacao=?,data=?,observacao=?,status=? WHERE id_agendamento=?',[$doctor,$tag,$date,$note,$status,$id]);
        else $id=(int)query('INSERT INTO agendamento (id_medico,id_usuario,id_abreviacao,data,observacao,status) VALUES (?,?,?,?,?,?) RETURNING id_agendamento',[$doctor,$user['id_usuario'],$tag,$date,$note,$status])->fetchColumn();
        return require_record('agendamento','id_agendamento',$id);
    });
}

function apply_fixed(array $data, array $user): array {
    $year=$data['ano']??null;
    if (!is_int($year) || $year<2000 || $year>2100) throw new ApiError(422,'Ano inválido (2000 a 2100).');
    return transaction(function () use ($year,$user): array {
        query('SELECT pg_advisory_xact_lock(73451002)');
        $count=query("INSERT INTO agendamento (id_medico,id_usuario,data)
            SELECT m.id_medico, CAST(? AS integer), dias.data::date
            FROM medico m JOIN medico_dia_semana d ON d.id_medico=m.id_medico
            CROSS JOIN generate_series(CAST(? AS date),CAST(? AS date),interval '1 day') AS dias(data)
            WHERE m.ativo=TRUE AND m.tipo='fixo' AND EXTRACT(DOW FROM dias.data)=d.dia_semana
            AND NOT EXISTS (SELECT 1 FROM agendamento a WHERE a.id_medico=m.id_medico AND a.data=dias.data::date)
            AND NOT EXISTS (SELECT 1 FROM feriado f WHERE f.data=dias.data::date)
            ON CONFLICT DO NOTHING",[$user['id_usuario'],"$year-01-01","$year-12-31"])->rowCount();
        return ['ano'=>$year,'criados'=>$count];
    });
}

function save_schedule(array $data, string $date, array $user): ?array {
    valid_date($date);
    $names=$data['profissionais']??null;
    if (!is_array($names) || !array_is_list($names) || count($names)>12) throw new ApiError(422,'Informe até 12 profissionais em uma lista.');
    $normalized=[];
    foreach ($names as $name) $normalized[]=text_field(['nome'=>$name],'nome',150);
    if (count(array_unique($normalized))!==count($normalized)) throw new ApiError(422,'Não repita nomes na escala.');
    $note=text_field($data,'observacao',3000,false);
    return transaction(function () use ($normalized,$note,$date,$user): ?array {
        query('SELECT pg_advisory_xact_lock(73451003)');
        $id=query('SELECT id_escala FROM escala WHERE data=?',[$date])->fetchColumn();
        if ($id) query('DELETE FROM escala_profissional WHERE id_escala=?',[$id]);
        if (!$normalized && !$note) {
            if ($id) query('DELETE FROM escala WHERE id_escala=?',[$id]);
            return null;
        }
        if ($id) query('UPDATE escala SET observacao=?,id_usuario=? WHERE id_escala=?',[$note,$user['id_usuario'],$id]);
        else $id=query('INSERT INTO escala (data,id_usuario,observacao) VALUES (?,?,?) RETURNING id_escala',[$date,$user['id_usuario'],$note])->fetchColumn();
        foreach ($normalized as $index=>$name) query('INSERT INTO escala_profissional (id_escala,nome_profissional,ordem) VALUES (?,?,?)',[$id,$name,$index+1]);
        return schedules($date,(new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d'))[0];
    });
}

function save_abbreviation(array $data, ?int $id): array {
    $old=$id ? require_record('abreviacao','id_abreviacao',$id) : [];
    $data=array_replace($old,$data);
    $code=text_field($data,'sigla',20);
    $description=text_field($data,'descricao',255);
    if ($id) query('UPDATE abreviacao SET sigla=?,descricao=? WHERE id_abreviacao=?',[$code,$description,$id]);
    else $id=(int)query('INSERT INTO abreviacao (sigla,descricao) VALUES (?,?) RETURNING id_abreviacao',[$code,$description])->fetchColumn();
    return require_record('abreviacao','id_abreviacao',$id);
}

function users(): array {
    $rows=query('SELECT id_usuario,nome,email,papel,ativo FROM usuario ORDER BY nome,id_usuario')->fetchAll();
    foreach ($rows as &$row) $row['papel']=role($row['papel']);
    return $rows;
}

function save_user(array $data, ?int $id, array $actor): array {
    return transaction(function () use ($data,$id,$actor): array {
        query('SELECT pg_advisory_xact_lock(73451004)');
        $old=$id ? require_record('usuario','id_usuario',$id) : [];
        if ($old) $old['papel']=role($old['papel']);
        $data=array_replace($old,$data);
        $name=text_field($data,'nome',150);
        $email=strtolower(text_field($data,'email',150));
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new ApiError(422,'E-mail inválido.');
        $permission=$data['papel']??'viewer';
        if (!in_array($permission,['adm','editor','viewer'],true)) throw new ApiError(422,'Papel inválido. Use adm, editor ou viewer.');
        $active=bool_field($data,'ativo');
        if ($id === (int)$actor['id_usuario'] && (!$active || $permission!=='adm')) throw new ApiError(409,'Você não pode remover seu próprio acesso de administrador.');
        if ($id && $old['ativo'] && role($old['papel'])==='adm' && (!$active || $permission!=='adm')) {
            $admins=array_filter(users(),fn($u)=>$u['ativo'] && role($u['papel'])==='adm');
            if (count($admins)<=1) throw new ApiError(409,'É necessário manter um administrador ativo.');
        }
        $password=$data['senha']??'';
        if (!is_string($password) || ((!$id || $password!=='') && (strlen($password)<10 || strlen($password)>72))) throw new ApiError(422,'A senha deve ter entre 10 e 72 bytes.');
        $hash=$password!=='' ? password_hash($password,PASSWORD_DEFAULT) : ($old['senha_hash']??'');
        if ($id) query('UPDATE usuario SET nome=?,email=?,papel=?,ativo=?,senha_hash=? WHERE id_usuario=?',[$name,$email,$permission,$active,$hash,$id]);
        else $id=(int)query('INSERT INTO usuario (nome,email,papel,ativo,senha_hash) VALUES (?,?,?,?,?) RETURNING id_usuario',[$name,$email,$permission,$active,$hash])->fetchColumn();
        if ($id === (int)$actor['id_usuario']) $_SESSION['credential']=hash('sha256',$hash);
        return query('SELECT id_usuario,nome,email,papel,ativo FROM usuario WHERE id_usuario=?',[$id])->fetch();
    });
}

function login(array $data): array {
    $email=strtolower(text_field($data,'email',150));
    $password=$data['senha']??'';
    if (!is_string($password) || strlen($password)>1024) throw new ApiError(422,'Senha inválida.');
    $key=hash('sha256',client_ip());
    $attempt=query("INSERT INTO login_tentativa (chave) VALUES (?) ON CONFLICT (chave) DO UPDATE SET
        tentativas=CASE WHEN login_tentativa.inicio < NOW()-interval '15 minutes' THEN 1 ELSE login_tentativa.tentativas+1 END,
        inicio=CASE WHEN login_tentativa.inicio < NOW()-interval '15 minutes' THEN NOW() ELSE login_tentativa.inicio END RETURNING tentativas",[$key])->fetchColumn();
    query("DELETE FROM login_tentativa WHERE inicio < NOW()-interval '1 day'");
    if ((int)$attempt>10) { header('Retry-After: 900'); throw new ApiError(429,'Muitas tentativas. Tente novamente em 15 minutos.'); }
    $user=query('SELECT * FROM usuario WHERE LOWER(email)=?',[$email])->fetch();
    $hash=$user['senha_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    $verified=password_verify($password,$hash);
    if (!$verified || !$user || !$user['ativo']) throw new ApiError(401,'E-mail ou senha inválidos.');
    if (password_needs_rehash($hash,PASSWORD_DEFAULT)) {
        $user['senha_hash']=password_hash($password,PASSWORD_DEFAULT);
        query('UPDATE usuario SET senha_hash=? WHERE id_usuario=?',[$user['senha_hash'],$user['id_usuario']]);
    }
    query('DELETE FROM login_tentativa WHERE chave=?',[$key]);
    session_regenerate_id(true);
    $_SESSION['user_id']=(int)$user['id_usuario'];
    $_SESSION['credential']=hash('sha256',$user['senha_hash']);
    $_SESSION['csrf']=bin2hex(random_bytes(32));
    return ['usuario'=>current_user(),'csrf'=>$_SESSION['csrf']];
}
