<?php

namespace App\Http\Controllers\ApiClinicas;

use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use App\Models\Clinicas\User;
use App\Models\Clinicas\Paciente;
use App\Http\Controllers\Controller as BaseController;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Repositories\Clinicas\AdministradorRepository;
use App\Services\Clinicas\AdministradorService;
use App\Repositories\Gerenciamento\DominioRepository;
use App\Repositories\Clinicas\PacienteRepository;
use App\Services\Clinicas\PacienteService;
use App\Repositories\Clinicas\EmpresaRepository;
use App\Helpers\Functions;
use App\Services\Clinicas\LogAtividadesService;

class AuthController extends BaseController {

    private $administradorService;
    private $pacienteService;

    public function __construct() {
        $this->middleware('auth:clinicas', ['except' => ['login', 'register', 'loginPorPerfil', 'esqueciSenha', 'registerPacienteLogin']]);
        $this->middleware('auth:clinicas_pacientes', ['except' => ['login', 'register', 'loginPorPerfil', 'esqueciSenha', 'registerPacienteLogin']]);

        $this->administradorService = new AdministradorService();
        $this->pacienteService = new PacienteService();
    }

    public function getAuthPassword() {
        return $this->senha;
    }

    /**
     * Store a new user.
     *
     * @param  Request  $request
     * @return Response
     */
    public function register(Request $request) {
//validate incoming request 
//           var_dump($request);

        $validate = Validator::make($request->all(), [
                    'login' => 'required|string|unique:users',
                    'senha' => 'required|confirmed',
                        ], [
                    'login.required' => 'Informe o  e-mail',
                    'login.unique' => 'Este e-mail já existe',
        ]);

        try {
            $user = new User;
            $user->login = $request->input('login');
            $user->senha = md5($request->input('senha'));
            $user->password = app('hash')->make($request->input('password'));
            $user->save();

            return response()->json([
                        'entity' => 'users',
                        'action' => 'create',
                        'result' => 'success'
                            ], 201);
        } catch (\Exception $e) {


            return response()->json([
                        'message' => '',
                        'error' => $validate->errors()
                            ], 409);
        }
    }

    private function generateHashAuthDocBiz($user) {
        $authTokenDocbiz = hash('sha256', time() . $user->id);

        $user->auth_token_docbiz = $authTokenDocbiz;
        $user->timestamps = false;
        $user->save();
        return $authTokenDocbiz;
    }

    private function generateHashAuthPacienteBiometria($user) {
        $authTokenBio = hash('sha256', time() . $user->id);

        $user->auth_token_biometria = $authTokenBio;
        $user->timestamps = false;
        $user->save();
        return $authTokenBio;
    }

    /**
     * Get a JWT via given credentials.
     *
     * @param  Request  $request
     * @return Response
     */
    public function login(Request $request) {

        $origemLogin = $_SERVER['HTTP_USER_AGENT'];

//validate incoming request 
        $this->validate($request, [
            'perfil_id' => 'required|numeric',
                ], [
            'perfil_id.required' => "Informe o campo id fo perfil",
        ]);
        $credentials = $request->only(['email', 'password']);

        $UserObj = new User;
        if ($request->has('authTokenBio') and !empty($request->input('authTokenBio'))) {
            $user = $UserObj::where('auth_token_docbiz', $request->input('authTokenBio'))
                    ->where('identificador', $request->input('perfil_id'))
                    ->first();
        } else {


            $this->validate($request, [
                'email' => 'required_if:|string',
                'password' => 'required|string',
                'password' => 'required|string',
            ]);

            $user = $UserObj::where('email', $request->input('email'))
                    ->where('senha', md5($request->input('password')))
                    ->where('identificador', $request->input('perfil_id'))
                    ->first();
        }


        if ($user != null) {
            $authTokenDocbiz = $this->generateHashAuthDocBiz($user);

            if (!$token = Auth::guard('clinicas')->setTTL(7200)->login($user)) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }
        } else {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $LogAtividadesService = new LogAtividadesService();
        $LogAtividadesService->store($user->identificador, 1, 'Efetuou login no sistema!', null, null, $origemLogin);
        return $this->respondWithToken($token, $authTokenDocbiz);
    }

    public function loginPorPerfil(Request $request) {

        $origemLogin = $_SERVER['HTTP_USER_AGENT'];
        $this->validate($request, [
            'perfil_id' => 'required|numeric',
                ], [
            'perfil_id.required' => "Informe o campo id fo perfil",
        ]);

        $idDominio = $request->input('perfil_id');
        $email = $request->input('email');
        $senha = $request->input('password');

        ////LOGIN PACIENTES
        if ($request->has('paciente') and $request->input('paciente') == true) {

            $PacienteModel = new Paciente;

            if ($request->has('authTokenBio') and !empty($request->input('authTokenBio'))) {
                $rowPaciente = $PacienteModel->validateLoginTokenBio($idDominio, $request->input('authTokenBio'));
            } else {
                $rowPaciente = $user = $PacienteModel->validateLogin($idDominio, $email, $senha);
            }
            if ($rowPaciente) {




                if (!$token = Auth::guard('clinicas_pacientes')->setTTL(7200)->login($rowPaciente)) {
                    return response()->json(['message' => 'Unauthorized'], 401);
                }
            } else {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            if ($request->has('authTokenBio') and !empty($request->input('authTokenBio'))) {
                $authTokenBio = $this->generateHashAuthPacienteBiometria($rowPaciente);
            } else {
                $authTokenBio = (empty($user->auth_token_biometria)) ? $this->generateHashAuthPacienteBiometria($rowPaciente) : $rowPaciente->auth_token_biometria;
            }

            return $this->respondWithToken($token, $authTokenBio);
        } else {

            ////LOGIN DOUTORES
            $UserModel = new User;

            $DominioRepository = new DominioRepository;
            $dominiosDocBiz = [];
            $qrDominioDocBiz = $DominioRepository->getDominiosDocBiz();
            foreach ($qrDominioDocBiz as $chave => $rowDominioDoc) {
                $dominiosDocBiz[] = $rowDominioDoc->dominio_id;
            }



            if ($request->has('authTokenBio') and !empty($request->input('authTokenBio'))) {
                $user = $UserModel->validateLoginTokenBio($dominiosDocBiz, $request->input('authTokenBio'));
            } else {
                $this->validate($request, [
                    'email' => 'required|string',
                    'password' => 'required|string',
                    'perfil_id' => 'required|numeric',
                        ], [
                    'email.required' => 'E-mail inválido',
                    'password.required' => "Informe o campo 'password' ",
                    'perfil_id.required' => "Informe o campo id fo perfil",
                ]);
                $usuarioIdSelecionado = ($request->has('usuarioIdSelecionado') and !empty($request->input('usuarioIdSelecionado'))) ? $request->input('usuarioIdSelecionado') : null;
                $user = $UserModel->validateLogin($dominiosDocBiz, $email, $senha, $usuarioIdSelecionado);

                //verificando se existe p mesmo email e senha em dois ou mias perfis
                if (count($user) > 1) {
                    foreach ($user as $rowUser) {

                        $rowDominioUser = $DominioRepository->getById($rowUser->identificador);
                        $EmpresaRepository = new EmpresaRepository;
                        $rowEmpresa = $EmpresaRepository->getById($rowUser->identificador);

                        $listLoginMultiplo[] = [
                            'id' => $rowUser->id,
                            'nome' => Functions::utf8ToAccentsConvert($rowUser->nome),
                            'email' => $rowUser->email,
                            'perfil' => ['id' => $rowUser->identificador,
                                'nome' => $rowDominioUser->dominio,
                                'nomeEmpresa' => $rowEmpresa[0]->nome,
                            ],
                        ];
                    }

                    return response()->json(['data' => $listLoginMultiplo], 200);
                }
            }


            if (count($user) > 0) {
                $user = $user[0];
                if (!$token = Auth::guard('clinicas')->setTTL(7200)->login($user)) {
                    return response()->json(['message' => 'Unauthorized'], 401);
                }
            } else {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            if ($request->has('authTokenBio') and !empty($request->input('authTokenBio'))) {
                $authTokenDocbiz = $this->generateHashAuthDocBiz($user);
            } else {

                $authTokenDocbiz = (empty($user->auth_token_docbiz)) ? $this->generateHashAuthDocBiz($user) : $user->auth_token_docbiz;
            }

            $LogAtividadesService = new LogAtividadesService();
            $LogAtividadesService->store($user->identificador, 1, 'Efetuou login no sistema!', null, null, $origemLogin);
            return $this->respondWithToken($token, $authTokenDocbiz);
        }
    }

    /**
     * Get user details.
     *
     * @param  Request  $request
     * @return Response
     */
    public function me() {

        $AdministradorRepository = new AdministradorRepository;

        $retorno = null;

        if (auth('clinicas')->check()) {
            $user = auth('clinicas')->user();
            $rowUser = $AdministradorRepository->getById($user->identificador, $user->id);
            $nomeUser = explode(' ', $rowUser->nome);
            $nomeUser = $nomeUser[0];

            $nome = (!empty($rowUser->abreviacao)) ? $rowUser->abreviacao . ' ' . utf8_decode($nomeUser) : utf8_decode($nomeUser);

            $dados = [
                'id' => $user->id,
                'nome' => $nome,
                'email' => $user->email,
                'perfilUsuarioId' => $user->perfil_usuario_id,
                'pronome' => (!empty($rowUser->abreviacao)) ? $rowUser->abreviacao : null,
                'doutorId' => (!empty($user->doutor_user_vinculado)) ? $user->doutor_user_vinculado : null,
                'doutorNomeCompleto' => $rowUser->nomeDoutor,
                'permissaoOutrasAgendas' => ($rowUser->doutor_vinc_outras_agendas == 1) ? true : false,
//            'dout_ver_outros' =>$user->dout_ver_outros,
            ];
        } else
        if (auth('clinicas_pacientes')->check()) {

            $user = auth('clinicas_pacientes')->user();
            $PacienteRepository = new PacienteRepository;
            $rowPac = $PacienteRepository->getById($user->identificador, $user->id);
//            dd($user);
            $dados = [
                'id' => $user->id,
                'nome' => $rowPac->nome_cript,
                'sobrenome' => $rowPac->sobrenome_cript,
                'email' => $rowPac->email_cript,
                'telefone' => $rowPac->telefone_cript,
                'celular' => $rowPac->celular_cript,
                'patient' => true
            ];
        }
        return response()->json($dados);
    }

    public function logout() {

        auth('clinicas')->logout(true);

        return $this->sendSuccess([
                    'success' => true,
                    'message' => "Logout realizado com sucesso"
                        ], "Logout realizado com sucesso");
    }

    public function alterarSenha(Request $request) {

        app('translator')->setLocale('pt-br');

        $validate = validator($request->input(), [
            'oldPassword' => ['required'],
            'newPassword' => ['required', 'min:8', 'max:14', Password::min(8)->mixedCase()->numbers()->symbols(),],
            'confirmNewPassword' => 'required|same:newPassword',
                ], [
            'oldPassword.required' => "Informe a senha atual",
            'newPassword.required' => "Informe a nova senha",
            'newPassword.min' => "A nova senha dever ter no mínimo 8 caracteres",
            'newPassword.mixedCase' => "A nova senha dever ter no mínimo 8 caracteres",
            'confirmNewPassword.required' => "Confirme a nova senha",
            'confirmNewPassword.same' => "As novas senhas são diferentes",
                ], ['validation.min.string']);

        if ($validate->fails()) {
            return $this->sendError(
                            ['success' => false,
                                'data' => null,
                                'message' => $validate->errors()->all()[0]
            ]);
        } else {

            $result = $this->administradorService->alterarSenha(auth('clinicas')->user()->identificador, auth('clinicas')->user()->id, $request->input('newPassword'), true, $request->input('oldPassword'));

            return $result;
        }
    }

    public function esqueciSenha(Request $request) {
        app('translator')->setLocale('pt-br');

        $validate = validator($request->input(), ['email' => 'required|email',
            'codigo' => 'numeric|digits:6',
            'password' => ['required_with:codigo', 'min:8', 'max:14', Password::min(8)->mixedCase()->numbers()->symbols(),],
            'confirmPassword' => 'required_with:password|same:password',
                ], [
            'email.required' => 'Informe o e-mail',
            'email.email' => 'E-mail inválido',
            'codigo.numeric' => 'O código deve ser numérico',
            'codigo.digits' => 'O código deve ter 6 digitos',
            'password.required' => "Informe a nova senha",
            'password.min' => "A nova senha dever ter no mínimo 8 caracteres",
            'confirmPassword.required_with' => "Confirme a nova senha",
            'confirmPassword.same' => "As novas senhas são diferentes",
                ]
        );

        if ($validate->fails()) {
            return $this->sendError(
                            ['success' => false,
                                'data' => null,
                                'message' => $validate->errors()->all()[0]
            ]);
        } else {

            $idDominio = ($request->has('perfilId')) ? $request->input('perfilId') : null;

            if (!empty($idDominio)) {
                $DominioRepository = new DominioRepository;
                $rowDominio = $DominioRepository->getById($idDominio);

                $dominiosDocBiz = [];
                if ($rowDominio->alteracao_docbizz == 1) {
                    $qrDominioDocBiz = $DominioRepository->getDominiosDocBiz();
                    foreach ($qrDominioDocBiz as $chave => $rowDominioDoc) {
                        $dominiosDocBiz[] = $rowDominioDoc->dominio_id;
                    }
                    $idDominio = $dominiosDocBiz;
                }
            }


            if ($request->has('codigo') and !empty($request->input('codigo'))) {

                return $result = $this->administradorService->esqueciSenhaVerificaCodigo($idDominio, $request->input('email'), $request->input('codigo'), $request->input('password'));
            } else {
                return $result = $this->administradorService->esqueciSenha($idDominio, $request->input('email'));
            }
        }
    }

    //pacientes
    public function registerPacienteLogin(Request $request) {
//validate incoming request 
//           var_dump($request);

        $validate = Validator::make($request->all(), [
                    'nome' => 'required|string|min:3|max:255',
                    'sobrenome' => 'required|string|min:3|max:255',
                    'email' => 'required|email',
                    'senha' => 'required|min:8|max:16',
                    'perfilId' => 'required|numeric',
                    'telefone' => 'nullable|numeric',
                    'celular' => 'nullable|numeric',
                    'cpf ' => 'nullable|numeric|digits:11',
                        ], [
        ]);

        $user = new Paciente;

        $verificaExiste = $user->isExistsLogin($request->input('perfilId'), trim($request->input('email')));
        if ($verificaExiste) {
            return response()->json([
                        'success' => false,
                        'data' => '',
                        'message' => 'Este e-mail já está cadastrado',
            ]);
        }

        if ($request->has('cpf') and !empty($request->input('cpf')) and !Functions::validateCPF($request->input('cpf'))) {

            return response()->json([
                        'success' => false,
                        'data' => '',
                        'message' => 'CPF inválido',
            ]);
        }


        try {


            $dadosPaciente['nome'] = trim($request->input('nome'));
            $dadosPaciente['sobrenome'] = trim($request->input('sobrenome'));
            $dadosPaciente['email'] = trim($request->input('email'));
            $dadosPaciente['senha'] = trim($request->input('senha'));
            $dadosPaciente['telefone'] = trim($request->input('telefone'));
            $dadosPaciente['celular'] = trim($request->input('celular'));
            $dadosPaciente['identificador'] = trim($request->input('perfilId'));
            $dadosPaciente['cpf'] = ($request->has('cpf') and !empty($request->input('cpf'))) ?
                    Functions::cpfToNumber(trim($request->input('cpf'))) : '';
            $dadosPaciente['envia_email'] = true;

            $idPaciente = $user->storeLogin($dadosPaciente);

            return response()->json([
                        'success' => true,
                        'data' => ['id' => $idPaciente],
                        'message' => 'Paciente cadastrado com sucesso',
                            ], 200);
        } catch (\Exception $e) {


            return response()->json([
                        'success' => false,
                        'message' => '',
                        'data' => $validate->errors()
                            ], 409);
        }
    }

    public function esqueciSenhaPaciente(Request $request) {
        app('translator')->setLocale('pt-br');

        $validate = validator($request->input(), ['email' => 'required|email',
            'codigo' => 'numeric|digits:6',
            'password' => ['required_with:codigo', 'min:8', 'max:14', Password::min(8)->mixedCase()->numbers()->symbols(),],
            'confirmPassword' => 'required_with:password|same:password',
                ], [
            'email.required' => 'Informe o e-mail',
            'email.email' => 'E-mail inválido',
            'codigo.numeric' => 'O código deve ser numérico',
            'codigo.digits' => 'O código deve ter 6 digitos',
            'password.required' => "Informe a nova senha",
            'password.min' => "A nova senha dever ter no mínimo 8 caracteres",
            'confirmPassword.required_with' => "Confirme a nova senha",
            'confirmPassword.same' => "As novas senhas são diferentes",
                ]
        );

        if ($validate->fails()) {
            return $this->sendError(
                            ['success' => false,
                                'data' => null,
                                'message' => $validate->errors()->all()[0]
            ]);
        } else {

            $idDominio = ($request->has('perfilId')) ? $request->input('perfilId') : null;

            if ($request->has('codigo') and !empty($request->input('codigo'))) {
                return $result = $this->pacienteService->esqueciSenhaVerificaCodigo($idDominio, $request->input('email'), $request->input('codigo'), $request->input('password'));
            } else {

                return $result = $this->pacienteService->esqueciSenha($idDominio, $request->input('email'));
            }
        }
    }

    public function alterarSenhaPaciente(Request $request) {

        app('translator')->setLocale('pt-br');

        $validate = validator($request->input(), [
            'oldPassword' => ['required'],
            'newPassword' => ['required', 'min:8', 'max:14', Password::min(8)->mixedCase()->numbers()->symbols(),],
            'confirmNewPassword' => 'required|same:newPassword',
                ], [
            'oldPassword.required' => "Informe a senha atual",
            'newPassword.required' => "Informe a nova senha",
            'newPassword.min' => "A nova senha dever ter no mínimo 8 caracteres",
            'newPassword.mixedCase' => "A nova senha dever ter no mínimo 8 caracteres",
            'confirmNewPassword.required' => "Confirme a nova senha",
            'confirmNewPassword.same' => "As novas senhas são diferentes",
                ], ['validation.min.string']);

        if ($validate->fails()) {
            return $this->sendError(
                            ['success' => false,
                                'data' => null,
                                'message' => $validate->errors()->all()[0]
            ]);
        } else {

            $result = $this->pacienteService->alterarSenha(auth('clinicas_pacientes')->user()->identificador, auth('clinicas_pacientes')->user()->id, $request->input('newPassword'), true, $request->input('oldPassword'));

            return $result;
        }
    }

    public function loginInterno(Request $request) {
//validate incoming request 
        $this->validate($request, [
            'perfil_id' => 'required|numeric',
                ], [
            'perfil_id.required' => "Informe o campo id fo perfil",
        ]);

        $credentials = $request->only(['email', 'password']);

        $UserObj = new User;
        if ($request->has('authTokenBio') and !empty($request->input('authTokenBio'))) {
            $user = $UserObj::where('auth_token_docbiz', $request->input('authTokenBio'))
                    ->where('identificador', $request->input('perfil_id'))
                    ->first();
        } else {

            $this->validate($request, [
                'email' => 'required_if:|string',
                'password' => 'required|string',
                'password' => 'required|string',
            ]);
            $user = $UserObj::where('email', $request->input('email'))
                    ->where('senha', md5($request->input('password')))
                    ->where('identificador', $request->input('perfil_id'))
                    ->first();
        }


        if ($user != null) {
            $authTokenDocbiz = $this->generateHashAuthDocBiz($user);

            if (!$token = Auth::guard('clinicas')->setTTL(7200)->login($user)) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }
        } else {
            return response()->json(['message' => 'Unauthorized'], 401);
        }


        return $this->respondWithToken($token, $authTokenDocbiz);
    }
}
