<?php

abstract class Controller
{
    protected $controller_name;
    protected $action_name;
    protected $application;
    protected $request;
    protected $response;
    protected $session;
    protected $db_manager;
    protected $auth_actions = array();

    /**
     * Controller constructor.
     * @param Application $application
     */
    public function __construct(Application $application)
    {
        // コントローラ名からクラス名を逆算してプロパティに設定
        $this->controller_name = strtolower(substr(get_class($this), 0, -10));
        
        $this->application = $application;
        $this->request     = $application->getRequest();
        $this->response    = $application->getResponse();
        $this->session     = $application->getSession();
        $this->db_manager  = $application->getDbManager();
    }
    
    
    
    /**
     * アクションを実行
     *
     * @param string $action
     * @param array $params
     * @return string レスポンスとして返すコンテンツ
     * 
     * @throws HttpNotFoundException アクションが存在しない場合
     * @throws UnauthorizedActionException 認証が必須なアクションに認証前にアクセスした場合
     */
    public function run(string $action, $params = array())
    {
        $this->action_name = $action;
        
        // アクション名となるメソッド名をプロパティに格納
        $action_method = $action . 'Action';
        if (!method_exists($this, $action_method)) {
            // $action_methodの値のメソッドが存在しない場合404を返す
            $this->forward404();
        }
        
        if ($this->needsAuthentication($action) && !$this->session->isAuthenticated()) {
            // needsAuthenticationの返り値がtrueで、かつ未ログインである場合
            throw new UnauthorizedActionException();
        }
        
        // アクションを実行
        return $this->$action_method($params);
    }



    /**
     * ビューファイルのレンダリング
     *
     * @param array $variables テンプレートに渡す変数の連想配列
     * @param string $template ビューファイル名(nullの場合はアクション名を使う)
     * @param string $layout レイアウトファイル名
     * @return string レンダリングしたビューファイルの内容
     */
    protected function render($variables = array(), $template = null, $layout = 'layout'): string
    {
        // デフォルト値を連想配列で指定する
        $defaults = array(
            'request' => $this->request,
            'base_url' => $this->request->getBaseUrl(),
            'session' => $this->session,
        );
        
        // Viewクラスのインスタンスを作成 デフォルト値を引数として指定
        $view = new View($this->application->getViewDir(), $defaults);
        
        if (is_null($template)) {
            // テンプレート名が指定されてない場合はアクション名をファイル名として指定する
            $template = $this->action_name;
        }
        
        // コントローラ名をテンプレート名の先頭に付与する
        $path = $this->controller_name . '/' . $template;
        
        // ビューファイルの読み込みを実行する
        return $view->render($path, $variables, $layout);
    }



    /**
     * 404エラー画面を出力
     * アクション内でリクエストされた情報が存在しない場合このメソッドを呼び出してエラー画面に遷移する
     * 
     * @throws HttpNotFoundException
     */
    protected function forward404()
    {
        throw new HttpNotFoundException('Forwarded 404 page from'
            . $this->controller_name . '/' . $this->action_name);
    }



    /**
     * 指定されたURLへリダイレクト
     * 
     * @param string $url
     */
    protected function redirect($url)
    {
        if (!preg_match('#https?://#', $url)) {
            // https通信ではなかった場合
            $protocol = $this->request->isSsl() ? 'https://' : 'http://';
            $host = $this->request->getHost();
            $base_url = $this->request->getBaseUrl();
            
            // 絶対URLを指定する
            $url = $protocol . $host . $base_url . $url;
        }
        
        // Locationヘッダと302コードを指定し、リダイレクトする
        $this->response->setStatusCode(302, 'Found');
        $this->response->setHttpHeader('Location', $url);
    }



    /**
     * CSRFトークンを生成
     * 
     * @param string $form_name
     * @return string $token
     */
    protected function generateCsrfToken(string $form_name): string
    {
        $key = 'csrf_tokens/' . $form_name;
        $tokens = $this->session->get($key, array());
        if (count($tokens) >= 10) {
            // 最大10個のトークンを保持し、超えたら古いトークンから削除する
            array_shift($tokens);
        }
        
        // トークンを生成
        $token = sha1($form_name . session_id() . microtime());
        $tokens[] = $token;
        
        $this->session->set($key, $tokens);
        
        return $token;
    }



    /**
     * CSRFトークンが妥当かチェック
     * セッション上に格納されているトークンからPOSTされたトークンを探す
     * 
     * @param string $form_name
     * @param string $token
     * @return bool
     */
    protected function checkCsrfToken(string $form_name, string $token): bool
    {
        $key = 'csrf_tokens/' . $form_name;
        $tokens = $this->session->get($key, array());
        
        if (false !== ($pos = array_search($token, $tokens, true))) {
            // セッション上にトークンが格納されているかどうか判定
            unset($tokens[$pos]);
            $this->session->set($key, $tokens);
            
            return true;
        }
        return false;
    }



    /**
     * 指定されたアクションが認証済みでないとアクセスできないか判定
     * 
     * @param string $action
     * @return bool
     */
    protected function needsAuthentication(string $action): bool
    {
        if ($this->auth_actions === true || (is_array($this->auth_actions) && in_array($action, $this->auth_actions))) {
            // $auth_actionsプロパティを元に指定したアクションがログイン必須かどうかチェックする
            return true;
        }
        
        return false;
    }
}
