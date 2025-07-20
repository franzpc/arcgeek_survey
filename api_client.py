import json
import time
import threading
from typing import Optional

try:
    import requests
except ImportError:
    requests = None

try:
    from qgis.PyQt.QtCore import QObject, pyqtSignal
except ImportError:
    try:
        from PyQt6.QtCore import QObject, pyqtSignal
    except ImportError:
        from PyQt5.QtCore import QObject, pyqtSignal


class PluginTokenManager:
    def __init__(self):
        self.token = None
        self.token_expires = 0
        self.cache_duration = 1800
        self.lock = threading.Lock()
        
        self.supabase_url = 'https://neixcsnkwtgdxkucfcnb.supabase.co'
        self.supabase_key = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Im5laXhjc25rd3RnZHhrdWNmY25iIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NDk1NzQ0OTQsImV4cCI6MjA2NTE1MDQ5NH0.OLcE9XYvYL6vzuXqcgp3dMowDZblvQo8qR21Cj39nyY'
    
    def fetch_plugin_token(self) -> Optional[str]:
        if not requests:
            return None
        
        try:
            headers = {
                'apikey': self.supabase_key,
                'Authorization': f"Bearer {self.supabase_key}"
            }
            
            response = requests.get(
                f"{self.supabase_url}/rest/v1/sys_auth_configs",
                headers=headers,
                params={
                    'is_active': 'eq.true',
                    'order': 'created_at.desc',
                    'limit': 1
                },
                timeout=10
            )
            
            if response.status_code == 200:
                data = response.json()
                if data and len(data) > 0:
                    token = data[0].get('country_code')
                    print(f"Token obtenido desde Supabase: {token[:20]}...")
                    return token
            else:
                print(f"Error obteniendo token de Supabase: {response.status_code}")
            
        except Exception as e:
            print(f"Error en fetch_plugin_token: {e}")
            
        return None
    
    def get_current_token(self) -> Optional[str]:
        with self.lock:
            current_time = time.time()
            
            if self.token and (current_time - self.token_expires) < self.cache_duration:
                return self.token
            
            new_token = self.fetch_plugin_token()
            if new_token:
                self.token = new_token
                self.token_expires = current_time
                return self.token
            
            print("No se pudo obtener token válido")
            return None


class ArcGeekAPIClient(QObject):
    
    loginSuccess = pyqtSignal(dict)
    loginError = pyqtSignal(str)
    requestFinished = pyqtSignal(dict)
    requestError = pyqtSignal(str)
    configLoaded = pyqtSignal(dict)
    formsLoaded = pyqtSignal(list)

    def __init__(self, base_url="https://acolita.com/survey"):
        super().__init__()
        self.base_url = base_url.rstrip('/')
        self.user_data = None
        self.is_authenticated = False
        self.token_manager = None
        
        if requests:
            self.session = requests.Session()
            self.token_manager = PluginTokenManager()
        else:
            self.session = None

    def _ensure_token(self):
        if not self.token_manager:
            return False
            
        token = self.token_manager.get_current_token()
        if token:
            self.session.headers.update({'X-Plugin-Token': token})
            return True
        else:
            print("No se pudo obtener token de autenticación")
            return False

    def set_base_url(self, url):
        self.base_url = url.rstrip('/')

    def _make_request(self, method, endpoint, **kwargs):
        if not self.session or not requests:
            raise Exception("Requests library not available")
        
        needs_token = any(ep in endpoint for ep in [
            'public/api/user-forms.php',
            'public/api/responses-free.php', 
            'public/api/delete-form.php',
            'public/api/user-config.php',
            'plugin-message.php'
        ])
        
        if needs_token and not self._ensure_token():
            raise Exception("No valid authentication token available")
            
        max_retries = 2
        for attempt in range(max_retries):
            try:
                url = f"{self.base_url}/{endpoint.lstrip('/')}"
                response = self.session.request(method, url, **kwargs)
                
                if response.status_code == 401 and attempt < max_retries - 1 and needs_token:
                    with self.token_manager.lock:
                        self.token_manager.token = None
                        self.token_manager.token_expires = 0
                    
                    if self._ensure_token():
                        continue
                    else:
                        raise Exception("Unable to refresh authentication token")
                
                return response
                
            except Exception as e:
                if attempt == max_retries - 1:
                    raise e
                time.sleep(0.5)
        
        return None

    def test_connection(self):
        if not self.session:
            return False
            
        try:
            response = self.session.get(f"{self.base_url}/", timeout=5)
            return response.status_code in [200, 302, 404]
        except Exception:
            return False

    def login(self, email, password):
        if not self.session:
            self.loginError.emit("Connection library not available")
            return False
            
        try:
            response = self._make_request(
                'POST',
                'public/api/user-config.php',
                data={
                    'email': email,
                    'password': password
                },
                timeout=15
            )
            
            if response and response.status_code == 200:
                user_config = response.json()
                self.is_authenticated = True
                self.user_data = user_config
                self.loginSuccess.emit(user_config)
                self.configLoaded.emit(user_config)
                return True
            elif response and response.status_code == 401:
                try:
                    error_data = response.json()
                    self.loginError.emit(error_data.get('error', 'Invalid credentials'))
                except:
                    self.loginError.emit('Invalid credentials')
                return False
            else:
                status_code = response.status_code if response else 'Connection Error'
                self.loginError.emit(f"HTTP {status_code}")
                return False
                
        except Exception as e:
            error_msg = f"Connection error: {str(e)}"
            self.loginError.emit(error_msg)
            return False

    def logout(self):
        self.is_authenticated = False
        self.user_data = None

    def can_use_postgres(self):
        if not self.is_authenticated or not self.user_data:
            return False
        
        plan_type = self.user_data.get('plan_type', 'free')
        
        if plan_type == 'free':
            return False
        
        postgres_config = self.user_data.get('postgres', {})
        has_postgres_config = all([
            postgres_config.get('host'),
            postgres_config.get('database'),
            postgres_config.get('username'),
            postgres_config.get('password')
        ])
        
        return has_postgres_config

    def get_user_config(self):
        if not self.is_authenticated or not self.session:
            return None
        
        try:
            response = self._make_request(
                'POST',
                'public/api/user-config.php',
                data={
                    'email': self.user_data.get('email', ''),
                    'password': ''
                },
                timeout=10
            )
            
            if response and response.status_code == 200:
                config = response.json()
                self.configLoaded.emit(config)
                return config
            else:
                return None
                
        except Exception:
            return None

    def get_user_forms(self):
        if not self.is_authenticated or not self.session:
            return []
        
        try:
            response = self._make_request(
                'GET',
                'public/api/user-forms.php',
                params={'user_id': self.user_data.get('user_id', '')},
                timeout=15
            )
            
            if response and response.status_code == 200:
                forms = response.json()
                self.formsLoaded.emit(forms)
                return forms
            else:
                return []
                
        except Exception:
            return []

    def get_free_responses(self, limit=1000, offset=0):
        if not self.is_authenticated or not self.session:
            return []
        
        try:
            response = self._make_request(
                'GET',
                'public/api/responses-free.php',
                params={
                    'user_id': self.user_data.get('user_id', ''),
                    'limit': limit,
                    'offset': offset
                },
                timeout=30
            )
            
            if response and response.status_code == 200:
                return response.json()
            else:
                return []
                
        except Exception:
            return []

    def delete_form(self, form_id):
        if not self.is_authenticated or not self.session:
            return False
        
        try:
            response = self._make_request(
                'POST',
                'public/api/delete-form.php',
                json={
                    'user_id': self.user_data.get('user_id', ''),
                    'form_id': form_id
                },
                headers={'Content-Type': 'application/json'},
                timeout=15
            )
            
            if response and response.status_code == 200:
                result = response.json()
                return result.get('success', False)
            else:
                return False
                
        except Exception:
            return False

    def create_form(self, form_data):
        if not self.is_authenticated or not self.session:
            return None
        
        try:
            form_data['user_id'] = self.user_data.get('user_id', '')
            
            response = self._make_request(
                'POST',
                'public/api/create-form.php',
                json=form_data,
                headers={'Content-Type': 'application/json'},
                timeout=30
            )
            
            if response and response.status_code == 200:
                result = response.json()
                self.requestFinished.emit(result)
                return result
            else:
                error_msg = f"Failed to create form: {response.status_code if response else 'No response'}"
                self.requestError.emit(error_msg)
                return None
                
        except Exception as e:
            error_msg = f"Error creating form: {str(e)}"
            self.requestError.emit(error_msg)
            return None

    def get_plugin_message(self):
        try:
            plan_type = self.user_data.get('plan_type', 'free') if self.user_data else 'free'
            
            response = self._make_request(
                'GET',
                'plugin-message.php',
                params={'plan': plan_type},
                timeout=10
            )
            
            if response and response.status_code == 200:
                return response.json()
            else:
                return {'enabled': False, 'message': None}
                
        except Exception:
            return {'enabled': False, 'message': None}

    def get_form_responses(self, form_code, api_key=None):
        if not self.session:
            return None
            
        try:
            params = {'form_code': form_code}
            if api_key:
                params['api_key'] = api_key
            
            response = self._make_request(
                'GET',
                'public/api.php',
                params=params,
                timeout=30
            )
            
            if response and response.status_code == 200:
                return response.json()
            else:
                return None
                
        except Exception:
            return None

    def validate_database_connection(self, db_config):
        if not self.is_authenticated or not self.session:
            return False
        
        try:
            response = self._make_request(
                'POST',
                'dashboard/test-connection.php',
                json=db_config,
                headers={'Content-Type': 'application/json'},
                timeout=15
            )
            
            if response and response.status_code == 200:
                result = response.json()
                return result.get('success', False)
            else:
                return False
                
        except Exception:
            return False