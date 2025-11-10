import os
import sys
import requests
import json

try:
    from qgis.PyQt.QtCore import Qt, pyqtSlot, QT_VERSION_STR, QTimer, QCoreApplication
    from qgis.PyQt.QtWidgets import (
        QDialog, QVBoxLayout, QHBoxLayout, QTabWidget, QWidget,
        QLabel, QLineEdit, QPushButton, QTextEdit, QTableWidget,
        QTableWidgetItem, QComboBox, QCheckBox, QSpinBox,
        QGroupBox, QMessageBox, QProgressBar, QListWidget,
        QListWidgetItem, QSplitter, QFrame, QApplication, QHeaderView,
        QStackedWidget, QFormLayout, QTextBrowser, QScrollArea
    )
    from qgis.PyQt.QtGui import QFont, QPixmap, QIcon, QColor
    QT6_MODE = QT_VERSION_STR.startswith('6')
except ImportError:
    try:
        from PyQt6.QtCore import Qt, pyqtSlot, QTimer, QCoreApplication
        from PyQt6.QtWidgets import (
            QDialog, QVBoxLayout, QHBoxLayout, QTabWidget, QWidget,
            QLabel, QLineEdit, QPushButton, QTextEdit, QTableWidget,
            QTableWidgetItem, QComboBox, QCheckBox, QSpinBox,
            QGroupBox, QMessageBox, QProgressBar, QListWidget,
            QListWidgetItem, QSplitter, QFrame, QApplication, QHeaderView,
            QStackedWidget, QFormLayout, QTextBrowser, QScrollArea
        )
        from PyQt6.QtGui import QFont, QPixmap, QIcon, QColor
        QT6_MODE = True
    except ImportError:
        from PyQt5.QtCore import Qt, pyqtSlot, QTimer, QCoreApplication
        from PyQt5.QtWidgets import (
            QDialog, QVBoxLayout, QHBoxLayout, QTabWidget, QWidget,
            QLabel, QLineEdit, QPushButton, QTextEdit, QTableWidget,
            QTableWidgetItem, QComboBox, QCheckBox, QSpinBox,
            QGroupBox, QMessageBox, QProgressBar, QListWidget,
            QListWidgetItem, QSplitter, QFrame, QApplication, QHeaderView,
            QStackedWidget, QFormLayout, QTextBrowser, QScrollArea
        )
        from PyQt5.QtGui import QFont, QPixmap, QIcon, QColor
        QT6_MODE = False

try:
    from .api_client import ArcGeekAPIClient
    from .database_manager import DatabaseManager
    from .form_builder import FormBuilder
    from .layer_manager import LayerManager
    from .ui_components import UIComponents, OptionsDialog
except ImportError as e:
    print(f"Error importing plugin modules: {e}")
    raise

class ArcGeekDialog(QDialog):
    def __init__(self, plugin):
        super().__init__()
        self.plugin = plugin
        self.setWindowTitle("ArcGeek Survey")
        self.setMinimumSize(1000, 700)
        
        self.api_client = ArcGeekAPIClient()
        self.db_manager = DatabaseManager()
        self.form_builder = FormBuilder()
        self.layer_manager = LayerManager(self.db_manager, self.api_client)
        self.ui_components = UIComponents(self)
        
        self.current_forms = []
        self.user_config = None
        self.available_layers = []
        self.selected_form = None
        self.server_message = None
        self.field_options = {}
        
        self.setup_ui()
        self.connect_signals()
        self.load_settings()
        self.ui_components.apply_styles(self)

    def tr(self, text):
        return QCoreApplication.translate('ArcGeekSurvey', text)

    def normalize_field_name(self, field_name):
        import unicodedata
        import re
        
        normalized = field_name.lower().strip()
        
        replacements = {
            'ñ': 'n', 'á': 'a', 'é': 'e', 'í': 'i', 'ó': 'o', 'ú': 'u',
            'ü': 'u', 'ç': 'c', 'à': 'a', 'è': 'e', 'ì': 'i', 'ò': 'o', 'ù': 'u'
        }
        
        for char, replacement in replacements.items():
            normalized = normalized.replace(char, replacement)
        
        normalized = unicodedata.normalize('NFD', normalized)
        normalized = ''.join(c for c in normalized if unicodedata.category(c) != 'Mn')
        
        normalized = re.sub(r'[^a-z0-9_]', '_', normalized)
        normalized = re.sub(r'_+', '_', normalized)
        normalized = normalized.strip('_')
        
        reserved_words = [
            'select', 'insert', 'update', 'delete', 'from', 'where', 'join', 'inner', 'outer', 'left', 'right',
            'on', 'as', 'table', 'column', 'index', 'primary', 'foreign', 'key', 'constraint', 'alter',
            'create', 'drop', 'database', 'schema', 'view', 'trigger', 'function', 'procedure', 'begin',
            'end', 'if', 'then', 'else', 'case', 'when', 'group', 'order', 'by', 'having', 'limit',
            'offset', 'union', 'intersect', 'except', 'exists', 'in', 'not', 'and', 'or', 'like',
            'between', 'is', 'null', 'true', 'false', 'distinct', 'all', 'any', 'some', 'count',
            'sum', 'avg', 'min', 'max', 'user', 'role', 'grant', 'revoke', 'commit', 'rollback'
        ]
        
        if normalized in reserved_words:
            normalized = 'field_' + normalized
        
        if not normalized or normalized[0].isdigit():
            normalized = 'field_' + normalized
        
        if not normalized:
            normalized = 'campo'
        
        return normalized[:15]

    def generate_unique_field_name(self, display_name, existing_names):
        base_name = self.normalize_field_name(display_name)
        
        if base_name not in existing_names:
            return base_name
        
        counter = 1
        max_base_length = 13
        truncated_base = base_name[:max_base_length]
        
        while f"{truncated_base}_{counter}" in existing_names:
            counter += 1
            if len(f"{truncated_base}_{counter}") > 15:
                max_base_length -= 1
                truncated_base = base_name[:max_base_length]
                counter = 1
        
        return f"{truncated_base}_{counter}"[:15]

    def truncate_text(self, text, max_length=50):
        if len(text) <= max_length:
            return text
        return text[:max_length-3] + "..."

    def setup_ui(self):
        layout = QVBoxLayout(self)
        layout.setSpacing(10)
        layout.setContentsMargins(15, 15, 15, 15)
        
        header_layout = QHBoxLayout()
        title_label = QLabel("ArcGeek Survey")
        title_font = QFont()
        title_font.setPointSize(16)
        title_font.setBold(True)
        title_label.setFont(title_font)
        title_label.setStyleSheet("color: #2c3e50; margin-bottom: 10px;")
        header_layout.addWidget(title_label)
        header_layout.addStretch()
        
        status_label = QLabel()
        status_label.setObjectName("statusLabel")
        header_layout.addWidget(status_label)
        
        layout.addLayout(header_layout)
        
        self.tabs = QTabWidget()
        self.tabs.setTabPosition(QTabWidget.TabPosition.North)
        layout.addWidget(self.tabs)
        
        self.tabs.addTab(self.ui_components.create_config_tab(), self.tr("Connection"))
        self.tabs.addTab(self.ui_components.create_forms_tab(), self.tr("Forms"))
        self.tabs.addTab(self.ui_components.create_layers_tab(), self.tr("Layers"))
        
        button_layout = QHBoxLayout()
        self.about_btn = QPushButton(self.tr("About"))
        self.about_btn.clicked.connect(self.show_about)
        button_layout.addWidget(self.about_btn)
        
        button_layout.addStretch()
        
        self.close_btn = QPushButton(self.tr("Close"))
        self.close_btn.clicked.connect(self.close)
        self.close_btn.setDefault(True)
        button_layout.addWidget(self.close_btn)
        layout.addLayout(button_layout)

    def connect_signals(self):
        self.api_client.loginSuccess.connect(self.on_login_success)
        self.api_client.loginError.connect(self.on_login_error)
        self.api_client.configLoaded.connect(self.on_config_loaded)
        self.api_client.formsLoaded.connect(self.on_forms_loaded)
        self.api_client.requestFinished.connect(self.on_api_request_finished)
        self.api_client.requestError.connect(self.on_api_request_error)
        
        self.db_manager.connectionSuccess.connect(self.on_db_connection_success)
        self.db_manager.connectionError.connect(self.on_db_connection_error)
        self.db_manager.autoConnected.connect(self.on_postgres_auto_connected)
        self.db_manager.queryFinished.connect(self.on_query_finished)
        self.db_manager.queryError.connect(self.on_query_error)
        
        self.form_builder.formCreated.connect(self.on_form_package_created)
        self.form_builder.formValidated.connect(self.on_form_validated)
        self.form_builder.error.connect(self.on_form_error)
        
        self.layer_manager.layerAdded.connect(self.on_layer_added)
        self.layer_manager.layerError.connect(self.on_layer_error)
        self.layer_manager.layersRefreshed.connect(self.on_layers_refreshed)
        
        self.fields_table.itemChanged.connect(self.on_fields_changed)

    def load_settings(self):
        self.server_url_edit.setText(self.plugin.get_settings_value('server_url', 'https://acolita.com/survey'))
        self.email_edit.setText(self.plugin.get_settings_value('email', ''))
        
        saved_password = self.plugin.get_settings_value('password', '')
        if saved_password and self.email_edit.text():
            self.password_edit.setText(saved_password)
            QTimer.singleShot(500, self.auto_login)

    def auto_login(self):
        if self.email_edit.text() and self.password_edit.text():
            self.on_login()

    def save_settings(self):
        self.plugin.set_settings_value('server_url', self.server_url_edit.text())
        self.plugin.set_settings_value('email', self.email_edit.text())
        self.plugin.set_settings_value('remember_me', self.remember_me_check.isChecked())
        
        if self.remember_me_check.isChecked() and self.api_client.is_authenticated:
            self.plugin.set_settings_value('password', self.password_edit.text())
        else:
            self.plugin.set_settings_value('password', '')

    def load_server_message(self):
        if not self.api_client.is_authenticated or not self.user_config:
            return
        
        try:
            plan = self.user_config.get('plan_type', 'free')
            base_url = self.api_client.base_url
            
            response = requests.get(
                f"{base_url}/public/plugin-message.php",
                params={'plan': plan},
                headers={'X-Plugin-Token': 'ArcGeek@2025_M@sterKEy_2017202219851986'},
                timeout=10
            )
            
            if response.status_code == 200:
                data = response.json()
                if data.get('enabled') and data.get('message'):
                    message = data['message']
                    title = message.get('title', '').strip()
                    content = message.get('content', '').strip()
                    msg_type = message.get('type', 'info')
                    
                    if title and content:
                        display_text = f"<b>{title}</b><br>{content}"
                        self.server_message_label.setText(display_text)
                        
                        color_map = {
                            'info': '#17a2b8',
                            'success': '#28a745',
                            'warning': '#ffc107',
                            'danger': '#dc3545'
                        }
                        
                        color = color_map.get(msg_type, '#6c757d')
                        self.server_message_label.setStyleSheet(f"color: {color}; font-weight: bold; padding: 8px; background-color: #f8f9fa; border: 1px solid #e9ecef; border-radius: 4px;")
                        return
                        
            self.server_message_label.setText(self.tr("No messages"))
            self.server_message_label.setStyleSheet("color: #6c757d; font-style: italic; padding: 5px;")
                        
        except requests.exceptions.RequestException as e:
            print(f"Error loading server message: {e}")
            self.server_message_label.setText(self.tr("Connection error"))
            self.server_message_label.setStyleSheet("color: #dc3545; font-style: italic; padding: 5px;")
        except Exception as e:
            print(f"Error parsing server message: {e}")
            self.server_message_label.setText(self.tr("No messages"))
            self.server_message_label.setStyleSheet("color: #6c757d; font-style: italic; padding: 5px;")

    @pyqtSlot()
    def show_about(self):
        about_text = f"""
        <div style="text-align: center;">
            <h2 style="color: #2c3e50; margin-bottom: 20px;">ArcGeek Survey Plugin</h2>
            <p style="font-size: 14px; margin-bottom: 15px;"><b>{self.tr("Version")}:</b> 1.0.0</p>
            
            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 15px 0;">
                <p style="margin: 5px 0;"><b>{self.tr("Description")}:</b></p>
                <p style="margin: 5px 0;">{self.tr("Create and manage georeferenced surveys with PostgreSQL/PostGIS integration")}</p>
            </div>
            
            <div style="background: #e3f2fd; padding: 15px; border-radius: 8px; margin: 15px 0;">
                <p style="margin: 5px 0;"><b>{self.tr("Developer")}:</b> ARCGEEK S.A.S. B.I.C.</p>
                <p style="margin: 5px 0;"><b>{self.tr("Website")}:</b> <a href="https://acolita.com/survey" style="color: #1976d2;">https://acolita.com/survey</a></p>
                <p style="margin: 5px 0;"><b>{self.tr("Support")}:</b> <a href="mailto:support@arcgeek.com" style="color: #1976d2;">soporte@arcgeek.com</a></p>
            </div>
            
            <p style="font-style: italic; color: #666; margin-top: 20px;">
                {self.tr("This plugin allows you to create spatial surveys, collect GPS data, and manage responses through a web interface.")}
            </p>
            
            <div style="margin-top: 20px; padding: 10px; background: #fff3cd; border-radius: 4px;">
                <p style="margin: 0; font-size: 12px; color: #856404;">
                    <b>{self.tr("Note")}:</b> {self.tr("Requires internet connection and ArcGeek Survey account")}
                </p>
            </div>
        </div>
        """
        
        msg = QMessageBox(self)
        msg.setWindowTitle(self.tr("About ArcGeek Survey"))
        msg.setTextFormat(Qt.TextFormat.RichText)
        msg.setText(about_text)
        msg.setMinimumWidth(500)
        msg.exec()

    @pyqtSlot()
    def on_register_link_clicked(self):
        register_url = f"{self.server_url_edit.text().strip()}/auth/register.php"
        try:
            import webbrowser
            webbrowser.open(register_url)
        except:
            QMessageBox.information(self, self.tr("Register"), f"{self.tr('Open this URL in your browser')}:\n{register_url}")

    @pyqtSlot()
    def on_login(self):
        server_url = self.server_url_edit.text().strip()
        email = self.email_edit.text().strip()
        password = self.password_edit.text()
        
        if not server_url or not email or not password:
            QMessageBox.warning(self, self.tr("Error"), self.tr("Server URL, email and password are required"))
            return
        
        self.api_client.set_base_url(server_url)
        
        self.login_btn.setEnabled(False)
        self.login_btn.setText(self.tr("Connecting..."))
        
        self.api_client.login(email, password)

    @pyqtSlot(dict)
    def on_login_success(self, user_data):
        self.login_btn.setEnabled(True)
        self.login_btn.setText(self.tr("Disconnect"))
        self.login_btn.clicked.disconnect()
        self.login_btn.clicked.connect(self.on_logout)
        
        user_email = user_data.get('email', 'User')
        self.login_status.setText(f"{self.tr('Connected as')}: {user_email}")
        self.login_status.setObjectName("loginStatusConnected")
        self.login_status.setStyleSheet("color: #28a745; font-weight: bold;")
        self.register_link.hide()
        self.forgot_link.hide()
        
        self.save_settings()
        self.api_client.get_user_config()

    @pyqtSlot(str)
    def on_login_error(self, error):
        self.login_btn.setEnabled(True)
        self.login_btn.setText(self.tr("Connect"))
        
        self.login_status.setText(f"{self.tr('Error')}: {error}")
        self.login_status.setStyleSheet("color: #dc3545;")

    @pyqtSlot()
    def on_logout(self):
        self.api_client.logout()
        self.db_manager.disconnect()
        
        self.plugin.set_settings_value('password', '')
        self.password_edit.clear()
        
        self.login_btn.setText(self.tr("Connect"))
        self.login_btn.clicked.disconnect()
        self.login_btn.clicked.connect(self.on_login)
        
        self.login_status.setText(self.tr("Not connected"))
        self.login_status.setStyleSheet("color: #6c757d;")
        self.register_link.show()
        self.forgot_link.show()
        
        self.user_config = None
        self.update_ui_after_logout()

    @pyqtSlot(dict)
    def on_config_loaded(self, config):
        self.user_config = config
        self.update_user_info_display()
        self.auto_connect_postgres()
        self.update_forms_ui_state()
        self.update_layers_ui_state()
        self.api_client.get_user_forms()
        self.load_server_message()

    def update_user_info_display(self):
        if not self.user_config:
            return
        
        name = self.user_config.get('name', self.tr('Unknown'))
        plan = self.user_config.get('plan_type', 'unknown').title()
        storage_pref = self.user_config.get('storage_preference', 'unknown')
        
        self.user_name_label.setText(name)
        self.user_plan_label.setText(plan)
        self.user_storage_label.setText(storage_pref.title())
        
        postgres_config = self.user_config.get('postgres', {})
        if postgres_config.get('host'):
            config_text = f"{postgres_config['host']}/{postgres_config['database']}"
            self.postgres_config_label.setText(config_text)
            self.test_postgres_btn.setEnabled(True)
        else:
            self.postgres_config_label.setText(self.tr("Not configured"))
            self.test_postgres_btn.setEnabled(False)

    def auto_connect_postgres(self):
        if not self.user_config:
            return
        
        postgres_config = self.user_config.get('postgres', {})
        if postgres_config.get('host') and postgres_config.get('database'):
            self.postgres_status_label.setText(self.tr("Auto-connecting..."))
            self.db_manager.auto_connect_from_config(postgres_config)
        else:
            self.postgres_status_label.setText(self.tr("Not configured"))

    @pyqtSlot()
    def on_test_postgres(self):
        if not self.user_config:
            return
        
        postgres_config = self.user_config.get('postgres', {})
        if not postgres_config.get('host'):
            QMessageBox.warning(self, self.tr("Error"), self.tr("No PostgreSQL configuration found"))
            return
        
        self.test_postgres_btn.setEnabled(False)
        self.test_postgres_btn.setText(self.tr("Testing..."))
        
        self.db_manager.test_connection(
            postgres_config['host'],
            postgres_config.get('port', 5432),
            postgres_config['database'],
            postgres_config['username'],
            postgres_config.get('password', '')
        )

    @pyqtSlot(str)
    def on_db_connection_success(self, message):
        self.test_postgres_btn.setEnabled(True)
        self.test_postgres_btn.setText(self.tr("Connect"))
        self.test_postgres_btn.clicked.disconnect()
        self.test_postgres_btn.clicked.connect(self.on_connect_postgres)
        
        short_msg = message[:50] + "..." if len(message) > 50 else message
        self.postgres_status_label.setText(f"{self.tr('Test OK')}: {short_msg}")
        self.postgres_status_label.setStyleSheet("color: #28a745;")

    @pyqtSlot(str)
    def on_db_connection_error(self, error):
        self.test_postgres_btn.setEnabled(True)
        self.test_postgres_btn.setText(self.tr("Test Connection"))
        
        short_error = error[:50] + "..." if len(error) > 50 else error
        self.postgres_status_label.setText(f"{self.tr('Error')}: {short_error}")
        self.postgres_status_label.setStyleSheet("color: #dc3545;")

    @pyqtSlot()
    def on_connect_postgres(self):
        if not self.user_config:
            return
        
        postgres_config = self.user_config.get('postgres', {})
        connected = self.db_manager.auto_connect_from_config(postgres_config)
        
        if connected:
            self.test_postgres_btn.setText(self.tr("Disconnect"))
            self.test_postgres_btn.clicked.disconnect()
            self.test_postgres_btn.clicked.connect(self.on_disconnect_postgres)

    @pyqtSlot()
    def on_disconnect_postgres(self):
        self.db_manager.disconnect()
        
        self.test_postgres_btn.setText(self.tr("Test Connection"))
        self.test_postgres_btn.clicked.disconnect()
        self.test_postgres_btn.clicked.connect(self.on_test_postgres)
        
        self.postgres_status_label.setText(self.tr("Disconnected"))
        self.postgres_status_label.setStyleSheet("color: #6c757d;")
        
        self.update_layers_ui_state()

    @pyqtSlot(dict)
    def on_postgres_auto_connected(self, config_info):
        db_name = config_info['database']
        self.postgres_status_label.setText(f"{self.tr('Connected to')} {db_name}")
        self.postgres_status_label.setStyleSheet("color: #28a745;")
        
        self.test_postgres_btn.setText(self.tr("Disconnect"))
        self.test_postgres_btn.clicked.disconnect()
        self.test_postgres_btn.clicked.connect(self.on_disconnect_postgres)
        
        self.update_layers_ui_state()

    @pyqtSlot()
    def on_configure_web(self):
        if self.user_config:
            url = f"{self.api_client.base_url}/dashboard/settings.php"
        else:
            url = f"{self.server_url_edit.text().strip()}/auth/register.php"
        
        try:
            import webbrowser
            webbrowser.open(url)
        except:
            QMessageBox.information(self, self.tr("Configure Database"), f"{self.tr('Open this URL in your browser')}:\n{url}")

    def update_forms_ui_state(self):
        authenticated = self.api_client.is_authenticated
        
        self.create_form_btn.setEnabled(authenticated)
        self.refresh_forms_btn.setEnabled(authenticated)
        self.manage_forms_btn.setEnabled(authenticated)
        
        if authenticated:
            plan = self.user_config.get('plan_type', 'free')
            limits = self.form_builder.get_field_limits(plan)
            
            self.form_creation_status.setText(f"{self.tr('Plan')}: {plan.title()} | {self.tr('Max fields')}: {limits['fields']}")
            self.form_creation_status.setStyleSheet("color: #28a745; font-style: normal;")
        else:
            self.form_creation_status.setText(self.tr("Login required"))
            self.form_creation_status.setStyleSheet("color: #6c757d; font-style: italic;")

    def update_layers_ui_state(self):
        can_add_layers = self.layer_manager.can_add_layers()
        
        self.refresh_layers_btn.setEnabled(can_add_layers)
        self.add_selected_btn.setEnabled(can_add_layers)
        self.add_all_survey_btn.setEnabled(can_add_layers)
        
        if can_add_layers:
            status_parts = []
            if self.api_client.is_authenticated:
                status_parts.append(self.tr("ArcGeek connected"))
            if self.db_manager.is_connected():
                status_parts.append(self.tr("PostgreSQL connected"))
            
            self.layers_status.setText(" | ".join(status_parts))
            self.layers_status.setStyleSheet("color: #28a745;")
        else:
            self.layers_status.setText(self.tr("Connect to ArcGeek Survey and/or PostgreSQL first"))
            self.layers_status.setStyleSheet("color: #6c757d;")

    def update_ui_after_logout(self):
        self.user_name_label.setText(self.tr("Not logged in"))
        self.user_plan_label.setText(self.tr("Unknown"))
        self.user_storage_label.setText(self.tr("Not configured"))
        self.postgres_status_label.setText(self.tr("Not configured"))
        self.postgres_config_label.setText(self.tr("None"))
        self.postgres_status_label.setStyleSheet("")
        self.server_message_label.setText(self.tr("No messages"))
        self.server_message_label.setStyleSheet("color: #6c757d; font-style: italic; padding: 5px;")
        
        self.test_postgres_btn.setEnabled(False)
        self.test_postgres_btn.setText(self.tr("Test Connection"))
        
        self.forms_list.clear()
        self.layers_table.setRowCount(0)
        self.selected_form = None
        
        self.update_forms_ui_state()
        self.update_layers_ui_state()

    @pyqtSlot()
    def on_add_field(self):
        if not self.api_client.is_authenticated:
            QMessageBox.warning(self, self.tr("Error"), self.tr("Please login first"))
            return
        
        plan = self.user_config.get('plan_type', 'free')
        limits = self.form_builder.get_field_limits(plan)
        
        if self.fields_table.rowCount() >= limits['fields']:
            QMessageBox.warning(self, self.tr("Limit Reached"), f"{self.tr('Maximum')} {limits['fields']} {self.tr('fields for')} {plan} {self.tr('plan')}")
            return
        
        row = self.fields_table.rowCount()
        self.fields_table.insertRow(row)
        
        display_name_item = QTableWidgetItem("")
        self.fields_table.setItem(row, 0, display_name_item)
        
        type_combo = QComboBox()
        type_combo.addItems(["text", "email", "number", "textarea", "date", "url", "select", "radio", "checkbox"])
        type_combo.setCurrentText("text")
        type_combo.currentTextChanged.connect(lambda: self.on_field_type_changed(row))
        self.fields_table.setCellWidget(row, 1, type_combo)
        
        options_btn = QPushButton("-")
        options_btn.setEnabled(False)
        options_btn.clicked.connect(lambda: self.edit_field_options(row))
        self.fields_table.setCellWidget(row, 2, options_btn)
        
        required_check = QCheckBox()
        required_check.setChecked(False)
        self.fields_table.setCellWidget(row, 3, required_check)
        
        delete_btn = QPushButton("🗑️")
        delete_btn.setMaximumWidth(30)
        delete_btn.clicked.connect(lambda: self.delete_field_row(row))
        self.fields_table.setCellWidget(row, 4, delete_btn)
        
        self.field_options[row] = []
        self.update_fields_info()
    
    def on_field_type_changed(self, row):
        type_combo = self.fields_table.cellWidget(row, 1)
        options_btn = self.fields_table.cellWidget(row, 2)
        
        if type_combo and options_btn:
            field_type = type_combo.currentText()
            has_options = field_type in ['select', 'radio', 'checkbox']
            
            options_btn.setEnabled(has_options)
            
            if has_options:
                options_count = len(self.field_options.get(row, []))
                options_btn.setText(f"✓ {options_count}" if options_count > 0 else "Setup")
            else:
                options_btn.setText("-")
                self.field_options[row] = []

    def edit_field_options(self, row):
        display_name_item = self.fields_table.item(row, 0)
        if not display_name_item:
            return
        
        field_name = display_name_item.text() or f"Field {row + 1}"
        current_options = self.field_options.get(row, [])
        
        dialog = OptionsDialog(self, field_name, current_options)
        if dialog.exec() == QDialog.DialogCode.Accepted:
            new_options = dialog.get_options()
            self.field_options[row] = new_options
            
            options_btn = self.fields_table.cellWidget(row, 2)
            if options_btn:
                options_count = len(new_options)
                options_btn.setText(f"✓ {options_count}" if options_count > 0 else "Setup")

    def delete_field_row(self, row):
        if row in self.field_options:
            del self.field_options[row]
        
        new_options = {}
        for old_row, options in self.field_options.items():
            if old_row > row:
                new_options[old_row - 1] = options
            elif old_row < row:
                new_options[old_row] = options
        
        self.field_options = new_options
        self.fields_table.removeRow(row)
        self.update_fields_info()
        
        for i in range(self.fields_table.rowCount()):
            delete_btn = self.fields_table.cellWidget(i, 4)
            if delete_btn:
                delete_btn.clicked.disconnect()
                delete_btn.clicked.connect(lambda checked, r=i: self.delete_field_row(r))
            
            type_combo = self.fields_table.cellWidget(i, 1)
            if type_combo:
                type_combo.currentTextChanged.disconnect()
                type_combo.currentTextChanged.connect(lambda text, r=i: self.on_field_type_changed(r))
            
            options_btn = self.fields_table.cellWidget(i, 2)
            if options_btn:
                options_btn.clicked.disconnect()
                options_btn.clicked.connect(lambda checked, r=i: self.edit_field_options(r))

    def update_field_name(self, row):
        try:
            display_name_item = self.fields_table.item(row, 0)
            
            if not display_name_item:
                return
            
            display_name = display_name_item.text().strip()
            
            if display_name:
                truncated_display = self.truncate_text(display_name, 50)
                display_name_item.setToolTip(display_name)
                if len(display_name) > 50:
                    display_name_item.setText(truncated_display)
                
        except Exception as e:
            print(f"Error updating field name: {e}")
    
    def on_fields_table_item_changed(self, item):
        if item.column() == 0:
            row = item.row()
            self.update_field_name(row)

    @pyqtSlot()
    def on_remove_field(self):
        current_row = self.fields_table.currentRow()
        if current_row >= 0:
            self.delete_field_row(current_row)

    def update_fields_info(self):
        count = self.fields_table.rowCount()
        if self.user_config:
            plan = self.user_config.get('plan_type', 'free')
            limits = self.form_builder.get_field_limits(plan)
            max_fields = limits['fields']
        else:
            max_fields = 15
        
        self.fields_info_label.setText(f"{self.tr('Fields')}: {count}/{max_fields}")
        
        if count >= max_fields:
            self.fields_info_label.setStyleSheet("color: #dc3545; font-weight: bold;")
        elif count > max_fields * 0.8:
            self.fields_info_label.setStyleSheet("color: #ffc107; font-weight: bold;")
        else:
            self.fields_info_label.setStyleSheet("color: #28a745; font-weight: bold;")

    @pyqtSlot()
    def on_fields_changed(self):
        self.update_fields_info()

    @pyqtSlot()
    def on_create_form(self):
        if not self.api_client.is_authenticated:
            QMessageBox.warning(self, self.tr("Error"), self.tr("Not logged in"))
            return
        
        title = self.form_title_edit.text().strip()
        description = self.form_description_edit.toPlainText().strip()
        fields = self.get_fields_from_table()
        
        if not title:
            QMessageBox.warning(self, self.tr("Error"), self.tr("Form title is required"))
            self.form_title_edit.setFocus()
            return
        
        if not fields:
            QMessageBox.warning(self, self.tr("Error"), self.tr("At least one field is required"))
            return
        
        field_names = []
        for field in fields:
            if not field['name']:
                QMessageBox.warning(self, self.tr("Error"), self.tr("All fields must have a name"))
                return
            
            if field['name'] in field_names:
                QMessageBox.warning(self, self.tr("Error"), f"{self.tr('Duplicate field name')}: {field['name']}")
                return
            
            field_names.append(field['name'])
            
            if field['type'] in ['select', 'radio', 'checkbox'] and not field.get('options'):
                QMessageBox.warning(self, self.tr("Error"), f"{self.tr('Field')} '{field['label']}' {self.tr('requires at least one option')}")
                return
        
        plan = self.user_config.get('plan_type', 'free')
        can_use_postgres = self.api_client.can_use_postgres() and self.db_manager.is_connected()
        
        self.create_form_btn.setEnabled(False)
        self.create_form_btn.setText(self.tr("Creating..."))
        
        try:
            package = self.form_builder.create_form_package(title, description, fields, plan, can_use_postgres)
            if not package:
                self.create_form_btn.setEnabled(True)
                self.create_form_btn.setText(self.tr("Create Form"))
                return
        except Exception as e:
            self.create_form_btn.setEnabled(True)
            self.create_form_btn.setText(self.tr("Create Form"))
            QMessageBox.critical(self, self.tr("Error"), f"{self.tr('Error creating form package')}: {str(e)}")
            return

    @pyqtSlot(dict)
    def on_form_package_created(self, package):
        form_data = package['form_data']
        
        if form_data['creation_type'] == 'postgres':
            sql = form_data.get('sql')
            if sql and self.db_manager.is_connected():
                success = self.db_manager.create_table_from_sql(sql)
                if not success:
                    self.create_form_btn.setEnabled(True)
                    self.create_form_btn.setText(self.tr("Create Form"))
                    return
        
        self.api_client.create_form(form_data)

    @pyqtSlot(dict)
    def on_form_validated(self, validation):
        creation_type = validation['creation_type']
        table_name = validation['table_name']
        
        if creation_type == 'postgres':
            self.form_creation_status.setText(f"{self.tr('Will create PostgreSQL table')}: {table_name}")
        else:
            self.form_creation_status.setText(self.tr("Will use shared hosting database"))

    @pyqtSlot(str)
    def on_form_error(self, error):
        self.create_form_btn.setEnabled(True)
        self.create_form_btn.setText(self.tr("Create Form"))
        QMessageBox.critical(self, self.tr("Form Error"), f"{self.tr('Error creating form')}:\n{error}")

    @pyqtSlot(dict)
    def on_api_request_finished(self, data):
        self.create_form_btn.setEnabled(True)
        self.create_form_btn.setText(self.tr("Create Form"))
        
        form_code = data.get('form_code', self.tr('Unknown'))
        
        QMessageBox.information(self, self.tr("Success"), 
                              f"{self.tr('Form created successfully')}!\n{self.tr('Form Code')}: {form_code}\n\n{self.tr('You can now collect responses.')}")
        
        self.clear_form_fields()
        self.api_client.get_user_forms()

    @pyqtSlot(str)
    def on_api_request_error(self, error):
        self.create_form_btn.setEnabled(True)
        self.create_form_btn.setText(self.tr("Create Form"))
        QMessageBox.critical(self, self.tr("API Error"), f"{self.tr('Error')}: {error}")

    @pyqtSlot()
    def on_refresh_forms(self):
        if self.api_client.is_authenticated:
            self.refresh_forms_btn.setEnabled(False)
            self.refresh_forms_btn.setText(self.tr("Refreshing..."))
            self.api_client.get_user_forms()

    @pyqtSlot(list)
    def on_forms_loaded(self, forms):
        self.refresh_forms_btn.setEnabled(True)
        self.refresh_forms_btn.setText(self.tr("Refresh"))
        
        self.current_forms = forms
        self.populate_forms_list(forms)

    def populate_forms_list(self, forms):
        self.forms_list.clear()
        
        for form in forms:
            item_text = f"{form['title']} ({form['form_code']})"
            item = QListWidgetItem(item_text)
            item.setData(Qt.ItemDataRole.UserRole, form)
            
            if form['response_count'] >= form['max_responses']:
                item.setToolTip(self.tr("Form limit reached"))
                item.setBackground(QColor("#fff3cd"))
            else:
                item.setToolTip(f"{self.tr('Responses')}: {form['response_count']}/{form['max_responses']}")
            
            self.forms_list.addItem(item)

    def on_form_selection_changed(self, current, previous):
        if current:
            form_data = current.data(Qt.ItemDataRole.UserRole)
            self.selected_form = form_data
            self.copy_url_btn.setEnabled(True)
            self.open_form_btn.setEnabled(True)
            self.view_results_btn.setEnabled(True)
        else:
            self.selected_form = None
            self.copy_url_btn.setEnabled(False)
            self.open_form_btn.setEnabled(False)
            self.view_results_btn.setEnabled(False)

    @pyqtSlot()
    def on_manage_forms_web(self):
        if self.api_client.is_authenticated:
            url = f"{self.api_client.base_url}/dashboard/forms.php"
        else:
            url = f"{self.server_url_edit.text().strip()}/dashboard/"
        
        try:
            import webbrowser
            webbrowser.open(url)
        except:
            QMessageBox.information(self, self.tr("Manage Forms"), f"{self.tr('Open this URL in your browser')}:\n{url}")

    @pyqtSlot()
    def on_copy_form_url(self):
        if not self.selected_form:
            return
        
        url = self.selected_form['collection_url']
        try:
            clipboard = QApplication.clipboard()
            clipboard.setText(url)
            QMessageBox.information(self, self.tr("Copied"), self.tr("URL copied to clipboard"))
        except:
            QMessageBox.information(self, self.tr("URL"), f"{self.tr('Copy this URL')}:\n{url}")

    @pyqtSlot()
    def on_open_form_browser(self):
        if not self.selected_form:
            return
        
        url = self.selected_form['collection_url']
        try:
            import webbrowser
            webbrowser.open(url)
        except:
            QMessageBox.information(self, self.tr("Open Form"), f"{self.tr('Open this URL in your browser')}:\n{url}")

    @pyqtSlot()
    def on_view_results(self):
        if not self.selected_form:
            return
        
        form_code = self.selected_form['form_code']
        url = f"{self.api_client.base_url}/public/share.php?code={form_code}"
        
        try:
            import webbrowser
            webbrowser.open(url)
        except:
            QMessageBox.information(self, self.tr("View Results"), f"{self.tr('Open this URL in your browser')}:\n{url}")

    @pyqtSlot()
    def on_refresh_layers(self):
        self.refresh_layers_btn.setEnabled(False)
        self.refresh_layers_btn.setText(self.tr("Refreshing..."))
        
        postgres_connected = self.db_manager.is_connected()
        api_authenticated = self.api_client.is_authenticated
        
        if not postgres_connected and not api_authenticated:
            self.layers_status.setText(self.tr("No connections available - Login to ArcGeek or connect PostgreSQL"))
            self.refresh_layers_btn.setEnabled(True)
            self.refresh_layers_btn.setText(self.tr("Refresh Layers"))
            return
        
        try:
            layers = self.layer_manager.get_available_layers()
        except Exception as e:
            self.layers_status.setText(f"{self.tr('Error getting layers')}: {e}")
            self.refresh_layers_btn.setEnabled(True)
            self.refresh_layers_btn.setText(self.tr("Refresh Layers"))

    @pyqtSlot(list)
    def on_layers_refreshed(self, layers):
        self.refresh_layers_btn.setEnabled(True)
        self.refresh_layers_btn.setText(self.tr("Refresh Layers"))
        
        self.available_layers = layers
        self.layers_table.setRowCount(len(layers))
        
        for i, layer in enumerate(layers):
            check = QCheckBox()
            self.layers_table.setCellWidget(i, 0, check)
            
            self.layers_table.setItem(i, 1, QTableWidgetItem(layer.get('form_title', 'N/A')))
            
            source_color = "#007bff" if layer['source'] == 'postgres' else "#28a745"
            source_text = f"<span style='color: {source_color}; font-weight: bold;'>{layer['source'].title()}</span>"
            source_label = QLabel(source_text)
            self.layers_table.setCellWidget(i, 2, source_label)
            
            self.layers_table.setItem(i, 3, QTableWidgetItem(layer['schema']))
            self.layers_table.setItem(i, 4, QTableWidgetItem(layer['table']))
            self.layers_table.setItem(i, 5, QTableWidgetItem(layer['geom_column']))
            self.layers_table.setItem(i, 6, QTableWidgetItem(layer['geom_type']))
        
        self.layers_status.setText(f"{self.tr('Found')} {len(layers)} {self.tr('spatial layers')}")
        self.layers_status.setStyleSheet("color: #28a745;")

    @pyqtSlot()
    def on_add_selected_layers(self):
        selected_layers = []
        
        for i in range(self.layers_table.rowCount()):
            check = self.layers_table.cellWidget(i, 0)
            if check and check.isChecked():
                if i < len(self.available_layers):
                    selected_layers.append(self.available_layers[i])
        
        if not selected_layers:
            QMessageBox.warning(self, self.tr("Warning"), self.tr("No layers selected"))
            return
        
        added_count = self.layer_manager.add_multiple_layers(selected_layers)
        QMessageBox.information(self, self.tr("Success"), f"{self.tr('Added')} {added_count} {self.tr('layers to QGIS')}")

    @pyqtSlot()
    def on_add_all_survey_layers(self):
        survey_layers = self.layer_manager.get_all_survey_layers()
        
        if not survey_layers:
            QMessageBox.information(self, self.tr("Info"), self.tr("No survey layers found"))
            return
        
        added_count = self.layer_manager.add_multiple_layers(survey_layers)
        QMessageBox.information(self, self.tr("Success"), f"{self.tr('Added')} {added_count} {self.tr('survey layers to QGIS')}")

    @pyqtSlot(str, str)
    def on_layer_added(self, layer_name, geom_type):
        self.layers_status.setText(f"{self.tr('Added layer')}: {layer_name}")

    @pyqtSlot(str)
    def on_layer_error(self, error):
        QMessageBox.critical(self, self.tr("Layer Error"), error)

    @pyqtSlot(list)
    def on_query_finished(self, results):
        pass

    @pyqtSlot(str)
    def on_query_error(self, error):
        QMessageBox.critical(self, self.tr("Database Error"), f"{self.tr('Error')}: {error}")

    def get_fields_from_table(self):
        fields = []
        
        for i in range(self.fields_table.rowCount()):
            display_name_item = self.fields_table.item(i, 0)
            type_combo = self.fields_table.cellWidget(i, 1)
            required_check = self.fields_table.cellWidget(i, 3)
            
            if not display_name_item or not display_name_item.text().strip():
                continue
                
            if not type_combo or not required_check:
                continue
            
            display_name = display_name_item.text().strip()
            field_type = type_combo.currentText()
            is_required = required_check.isChecked()
            
            existing_names = []
            for j in range(i):
                prev_display = self.fields_table.item(j, 0)
                if prev_display and prev_display.text().strip():
                    prev_field_name = self.generate_unique_field_name(prev_display.text().strip(), existing_names)
                    existing_names.append(prev_field_name)
            
            field_name = self.generate_unique_field_name(display_name, existing_names)
            
            field = {
                'name': field_name,
                'type': field_type,
                'required': is_required,
                'label': display_name
            }
            
            if field_type in ['select', 'radio', 'checkbox']:
                field['options'] = self.field_options.get(i, [])
            
            fields.append(field)
        
        return fields

    def clear_form_fields(self):
        self.form_title_edit.clear()
        self.form_description_edit.clear()
        self.fields_table.setRowCount(0)
        self.field_options.clear()
        self.update_fields_info()

    def closeEvent(self, event):
        self.save_settings()
        self.db_manager.disconnect()
        super().closeEvent(event)