"""Exercise polling while the originating PHP request holds its session lock."""
import concurrent.futures, json, os, pathlib, signal, socket, subprocess, tempfile, time, urllib.request, urllib.error
root=pathlib.Path(__file__).resolve().parents[1]
php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as tmp:
    router=pathlib.Path(tmp)/'router.php'
    router.write_text("<?php\nif(str_starts_with($_SERVER['REQUEST_URI'],'/api/')){require "+repr(str(root/'api/financial_workflow_progress.php'))+";return;}\nsession_start();if($_SERVER['REQUEST_METHOD']==='GET'){echo 'ready';return;}\nrequire "+repr(str(root/'services/FinancialWorkflowProgress.php'))+";FinancialWorkflowProgress::start();FinancialWorkflowProgress::step('Contrôle en cours.');usleep(1800000);header('Content-Type: application/json');echo '{\"ok\":true}';")
    sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
    server=subprocess.Popen([php,'-d','session.save_path='+tmp,'-S',f'127.0.0.1:{port}',str(router)],env={**os.environ,'PHP_CLI_SERVER_WORKERS':'2'},stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
    base=f'http://127.0.0.1:{port}'
    token=os.urandom(24).hex()
    try:
        for _ in range(50):
            try:
                response=urllib.request.urlopen(base+'/start',timeout=1);cookie=response.headers['Set-Cookie'].split(';')[0];break
            except (OSError,urllib.error.URLError):time.sleep(.05)
        else:raise AssertionError('PHP server unavailable')
        req=urllib.request.Request(base+'/decaissement/request/progress_test.php',data=b'csrf=fixture',headers={'Cookie':cookie,'X-Finea-Progress':token})
        with concurrent.futures.ThreadPoolExecutor() as pool:
            future=pool.submit(lambda:urllib.request.urlopen(req,timeout=5).read())
            time.sleep(.25)
            start=time.monotonic()
            poll=urllib.request.Request(base+'/api/financial_workflow_progress.php?token='+token,headers={'Cookie':cookie})
            data=json.load(urllib.request.urlopen(poll,timeout=1))
            assert time.monotonic()-start<1 and not future.done(), 'Polling blocked behind PHP session'
            assert data['state']=='running' and data['events'][-1]['message']=='Contrôle en cours.'
            assert 'owner' not in data and 'session_name' not in data
            try:urllib.request.urlopen(base+'/api/financial_workflow_progress.php?token='+token)
            except urllib.error.HTTPError as error:assert error.code==404
            else:raise AssertionError('Unauthenticated polling allowed')
            assert json.loads(future.result())['ok'] is True
        print('FinancialWorkflowProgressHttpTest: OK (live polling under session lock, owner isolation, one POST)')
    finally:
        os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=5)
        (pathlib.Path(tempfile.gettempdir())/'finea-workflow-progress-v1'/(token+'.json')).unlink(missing_ok=True)
