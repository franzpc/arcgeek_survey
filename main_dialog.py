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
except ImportError as e:
    print(f"Error importing plugin modules: {e}")
    raise

def get_qt_enum(enum_name):
    try:
        if QT6_MODE:
            if enum_name == 'Horizontal':
                return Qt.Orientation.Horizontal
            elif enum_name == 'Password':
                return QLineEdit.EchoMode.Password
            elif enum_name == 'Stretch':
                return QHeaderView.ResizeMode.Stretch
            elif enum_name == 'ResizeToContents':
                return QHeaderView.ResizeMode.ResizeToContents
        else:
            if enum_name == 'Horizontal':
                return Qt.Horizontal
            elif enum_name == 'Password':
                return QLineEdit.Password
            elif enum_name == 'Stretch':
                return QHeaderView.Stretch
            elif enum_name == 'ResizeToContents':
                return QHeaderView.ResizeToContents
    except:
        if enum_name == 'Horizontal':
            return 1
        elif enum_name == 'Password':
            return 2
        elif enum_name == 'Stretch':
            return 1
        elif enum_name == 'ResizeToContents':
            return 3
    return None

class ArcGeekDialog(QDialog):
    def __init__(self, plugin):
        super().__init__()
        self.plugin = plugin
        self.setWindowTitle("ArcGeek Survey")
        self.setMinimumSize(800, 600)
        
        self.api_client = ArcGeekAPIClient()
        self.db_manager = DatabaseManager()
        self.form_builder = FormBuilder()
        self.layer_manager = LayerManager(self.db_manager, self.api_client)
        
        self.current_forms = []
        self.user_config = None
        self.available_layers = []
        self.selected_form = None
        self.server_message = None
        
        self.setup_ui()
        self.connect_signals()
        self.load_settings()
        self.apply_styles()

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
        
        if normalized and normalized[0].isdigit():
            normalized = 'field_' + normalized
        
        if not normalized:
            normalized = 'campo'
        
        return normalized

    def generate_unique_field_name(self, display_name, existing_names):
        base_name = self.normalize_field_name(display_name)
        
        if base_name not in existing_names:
            return base_name
        
        counter = 1
        while f"{base_name}_{counter}" in existing_names:
            counter += 1
        
        return f"{base_name}_{counter}"

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
        
        self.setup_config_tab()
        self.setup_forms_tab()
        self.setup_layers_tab()
        
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

    def setup_config_tab(self):
        tab = QWidget()
        scroll = QScrollArea()
        scroll.setWidgetResizable(True)
        scroll.setWidget(tab)
        
        layout = QVBoxLayout(tab)
        layout.setSpacing(20)
        
        login_group = QGroupBox(self.tr("ArcGeek Survey Connection"))
        login_layout = QFormLayout(login_group)
        login_layout.setSpacing(12)
        
        self.server_url_edit = QLineEdit("https://acolita.com/survey")
        self.server_url_edit.setVisible(False)
        
        self.email_edit = QLineEdit()
        self.email_edit.setPlaceholderText(self.tr("Enter your email"))
        login_layout.addRow(self.tr("Email:"), self.email_edit)
        
        self.password_edit = QLineEdit()
        self.password_edit.setEchoMode(get_qt_enum('Password'))
        self.password_edit.setPlaceholderText(self.tr("Enter your password"))
        login_layout.addRow(self.tr("Password:"), self.password_edit)
        
        login_buttons = QHBoxLayout()
        self.login_btn = QPushButton(self.tr("Connect"))
        self.login_btn.clicked.connect(self.on_login)
        self.login_btn.setMinimumHeight(35)
        login_buttons.addWidget(self.login_btn)
        
        self.remember_me_check = QCheckBox(self.tr("Remember credentials"))
        self.remember_me_check.setChecked(True)
        login_buttons.addWidget(self.remember_me_check)
        login_buttons.addStretch()
        
        login_layout.addRow("", login_buttons)
        
        self.login_status = QLabel(self.tr("Not connected"))
        self.login_status.setObjectName("loginStatus")
        login_layout.addRow(self.tr("Status:"), self.login_status)
        
        links_layout = QHBoxLayout()
        self.register_link = QLabel(f'<a href="#" style="color: #3498db;">{self.tr("Create Account")}</a>')
        self.register_link.setOpenExternalLinks(False)
        self.register_link.linkActivated.connect(self.on_register_link_clicked)
        links_layout.addWidget(self.register_link)
        
        self.forgot_link = QLabel(f'<a href="https://acolita.com/survey/auth/forgot-password.php" style="color: #3498db;">{self.tr("Forgot Password?")}</a>')
        self.forgot_link.setOpenExternalLinks(True)
        links_layout.addWidget(self.forgot_link)
        links_layout.addStretch()
        
        login_layout.addRow("", links_layout)
        layout.addWidget(login_group)
        
        info_layout = QHBoxLayout()
        
        user_group = QGroupBox(self.tr("User Information"))
        user_layout = QFormLayout(user_group)
        
        self.user_name_label = QLabel(self.tr("Not logged in"))
        user_layout.addRow(self.tr("Name:"), self.user_name_label)
        
        self.user_plan_label = QLabel(self.tr("Unknown"))
        user_layout.addRow(self.tr("Plan:"), self.user_plan_label)
        
        self.user_storage_label = QLabel(self.tr("Not configured"))
        user_layout.addRow(self.tr("Storage:"), self.user_storage_label)
        
        info_layout.addWidget(user_group)
        
        db_group = QGroupBox(self.tr("PostgreSQL Database"))
        db_layout = QFormLayout(db_group)
        
        self.postgres_status_label = QLabel(self.tr("Not configured"))
        self.postgres_status_label.setObjectName("postgresStatus")
        db_layout.addRow(self.tr("Status:"), self.postgres_status_label)
        
        self.postgres_config_label = QLabel(self.tr("None"))
        db_layout.addRow(self.tr("Configuration:"), self.postgres_config_label)
        
        db_buttons = QHBoxLayout()
        self.test_postgres_btn = QPushButton(self.tr("Test Connection"))
        self.test_postgres_btn.clicked.connect(self.on_test_postgres)
        self.test_postgres_btn.setEnabled(False)
        db_buttons.addWidget(self.test_postgres_btn)
        
        self.configure_web_btn = QPushButton(self.tr("Configure Online"))
        self.configure_web_btn.clicked.connect(self.on_configure_web)
        db_buttons.addWidget(self.configure_web_btn)
        
        db_layout.addRow("", db_buttons)
        
        info_layout.addWidget(db_group)
        layout.addLayout(info_layout)
        
        message_group = QGroupBox(self.tr("Server Message"))
        message_layout = QVBoxLayout(message_group)
        
        self.server_message_label = QLabel(self.tr("No messages"))
        self.server_message_label.setObjectName("serverMessage")
        self.server_message_label.setWordWrap(True)
        self.server_message_label.setStyleSheet("color: #6c757d; font-style: italic; padding: 5px;")
        message_layout.addWidget(self.server_message_label)
        
        layout.addWidget(message_group)
        
        layout.addStretch()
        self.tabs.addTab(scroll, self.tr("Connection"))

    def setup_forms_tab(self):
        tab = QWidget()
        layout = QVBoxLayout(tab)
        layout.setSpacing(15)
        
        splitter = QSplitter()
        splitter.setOrientation(get_qt_enum('Horizontal'))
        
        left_widget = QWidget()
        left_layout = QVBoxLayout(left_widget)
        left_layout.setSpacing(15)
        
        form_info_group = QGroupBox(self.tr("Create New Form"))
        form_info_layout = QFormLayout(form_info_group)
        form_info_layout.setSpacing(10)
        
        self.form_title_edit = QLineEdit()
        self.form_title_edit.setPlaceholderText(self.tr("Enter form title"))
        form_info_layout.addRow(self.tr("Title:"), self.form_title_edit)
        
        self.form_description_edit = QTextEdit()
        self.form_description_edit.setMaximumHeight(60)
        self.form_description_edit.setPlaceholderText(self.tr("Optional description"))
        form_info_layout.addRow(self.tr("Description:"), self.form_description_edit)
        
        left_layout.addWidget(form_info_group)
        
        fields_group = QGroupBox(self.tr("Form Fields"))
        fields_layout = QVBoxLayout(fields_group)
        
        fields_header = QHBoxLayout()
        self.fields_info_label = QLabel(self.tr("Fields: 0/15"))
        self.fields_info_label.setObjectName("fieldsInfo")
        fields_header.addWidget(self.fields_info_label)
        fields_header.addStretch()
        
        self.add_field_btn = QPushButton(self.tr("Add Field"))
        self.add_field_btn.clicked.connect(self.on_add_field)
        self.add_field_btn.setIcon(QIcon(":/images/themes/default/symbologyAdd.svg"))
        fields_header.addWidget(self.add_field_btn)
        
        self.remove_field_btn = QPushButton(self.tr("Remove"))
        self.remove_field_btn.clicked.connect(self.on_remove_field)
        self.remove_field_btn.setIcon(QIcon(":/images/themes/default/symbologyRemove.svg"))
        fields_header.addWidget(self.remove_field_btn)
        
        fields_layout.addLayout(fields_header)
        
        self.fields_table = QTableWidget(0, 4)
        self.fields_table.setHorizontalHeaderLabels([
            self.tr("Display Name"), 
            self.tr("Field Name"),
            self.tr("Type"), 
            self.tr("Required")
        ])
        
        header = self.fields_table.horizontalHeader()
        header.setStretchLastSection(False)
        if hasattr(header, 'setSectionResizeMode'):
            header.setSectionResizeMode(0, get_qt_enum('Stretch'))
            header.setSectionResizeMode(1, get_qt_enum('Stretch'))
            header.setSectionResizeMode(2, get_qt_enum('ResizeToContents'))
            header.setSectionResizeMode(3, get_qt_enum('ResizeToContents'))
        else:
            header.setResizeMode(0, get_qt_enum('Stretch'))
            header.setResizeMode(1, get_qt_enum('Stretch'))
            header.setResizeMode(2, get_qt_enum('ResizeToContents'))
            header.setResizeMode(3, get_qt_enum('ResizeToContents'))
        
        self.fields_table.setAlternatingRowColors(True)
        self.fields_table.setSelectionBehavior(QTableWidget.SelectionBehavior.SelectRows)
        self.fields_table.itemChanged.connect(self.on_fields_table_item_changed)
        fields_layout.addWidget(self.fields_table)
        
        left_layout.addWidget(fields_group)
        
        create_layout = QHBoxLayout()
        self.create_form_btn = QPushButton(self.tr("Create Form"))
        self.create_form_btn.clicked.connect(self.on_create_form)
        self.create_form_btn.setEnabled(False)
        self.create_form_btn.setMinimumHeight(40)
        self.create_form_btn.setObjectName("createFormBtn")
        create_layout.addWidget(self.create_form_btn)
        
        self.form_creation_status = QLabel(self.tr("Login required"))
        self.form_creation_status.setObjectName("creationStatus")
        left_layout.addWidget(self.form_creation_status)
        left_layout.addLayout(create_layout)
        
        splitter.addWidget(left_widget)
        
        right_widget = QWidget()
        right_layout = QVBoxLayout(right_widget)
        right_layout.setSpacing(15)
        
        forms_group = QGroupBox(self.tr("Your Forms"))
        forms_layout = QVBoxLayout(forms_group)
        
        forms_header = QHBoxLayout()
        self.refresh_forms_btn = QPushButton(self.tr("Refresh"))
        self.refresh_forms_btn.clicked.connect(self.on_refresh_forms)
        self.refresh_forms_btn.setEnabled(False)
        self.refresh_forms_btn.setIcon(QIcon(":/images/themes/default/mActionRefresh.svg"))
        forms_header.addWidget(self.refresh_forms_btn)
        
        self.manage_forms_btn = QPushButton(self.tr("Manage Online"))
        self.manage_forms_btn.clicked.connect(self.on_manage_forms_web)
        self.manage_forms_btn.setEnabled(False)
        forms_header.addWidget(self.manage_forms_btn)
        
        forms_header.addStretch()
        forms_layout.addLayout(forms_header)
        
        self.forms_list = QListWidget()
        self.forms_list.setAlternatingRowColors(True)
        self.forms_list.currentItemChanged.connect(self.on_form_selection_changed)
        forms_layout.addWidget(self.forms_list)
        
        form_details_group = QGroupBox(self.tr("Form Details"))
        details_layout = QVBoxLayout(form_details_group)
        
        self.form_details = QTextBrowser()
        self.form_details.setMaximumHeight(120)
        self.form_details.setHtml(f"<p><i>{self.tr('Select a form to view details')}</i></p>")
        details_layout.addWidget(self.form_details)
        
        form_actions = QHBoxLayout()
        self.copy_url_btn = QPushButton(self.tr("Copy URL"))
        self.copy_url_btn.clicked.connect(self.on_copy_form_url)
        self.copy_url_btn.setEnabled(False)
        form_actions.addWidget(self.copy_url_btn)
        
        self.open_form_btn = QPushButton(self.tr("Open in Browser"))
        self.open_form_btn.clicked.connect(self.on_open_form_browser)
        self.open_form_btn.setEnabled(False)
        form_actions.addWidget(self.open_form_btn)
        
        form_actions.addStretch()
        details_layout.addLayout(form_actions)
        
        forms_layout.addWidget(form_details_group)
        right_layout.addWidget(forms_group)
        
        splitter.addWidget(right_widget)
        splitter.setSizes([700, 300])
        
        layout.addWidget(splitter)
        self.tabs.addTab(tab, self.tr("Forms"))

    def setup_layers_tab(self):
        tab = QWidget()
        layout = QVBoxLayout(tab)
        layout.setSpacing(15)
        
        controls_group = QGroupBox(self.tr("Layer Operations"))
        controls_layout = QHBoxLayout(controls_group)
        
        self.refresh_layers_btn = QPushButton(self.tr("Refresh Layers"))
        self.refresh_layers_btn.clicked.connect(self.on_refresh_layers)
        self.refresh_layers_btn.setEnabled(False)
        self.refresh_layers_btn.setIcon(QIcon(":/images/themes/default/mActionRefresh.svg"))
        controls_layout.addWidget(self.refresh_layers_btn)
        
        self.add_selected_btn = QPushButton(self.tr("Add Selected"))
        self.add_selected_btn.clicked.connect(self.on_add_selected_layers)
        self.add_selected_btn.setEnabled(False)
        self.add_selected_btn.setIcon(QIcon(":/images/themes/default/mActionAddLayer.svg"))
        controls_layout.addWidget(self.add_selected_btn)
        
        self.add_all_survey_btn = QPushButton(self.tr("Add All Survey Layers"))
        self.add_all_survey_btn.clicked.connect(self.on_add_all_survey_layers)
        self.add_all_survey_btn.setEnabled(False)
        controls_layout.addWidget(self.add_all_survey_btn)
        
        controls_layout.addStretch()
        layout.addWidget(controls_group)
        
        self.layers_table = QTableWidget(0, 7)
        self.layers_table.setHorizontalHeaderLabels([
            "", 
            self.tr("Form Title"),
            self.tr("Source"), 
            self.tr("Schema"), 
            self.tr("Table"), 
            self.tr("Geometry"), 
            self.tr("Type")
        ])
        
        layers_header = self.layers_table.horizontalHeader()
        layers_header.setStretchLastSection(True)
        if hasattr(layers_header, 'setSectionResizeMode'):
            layers_header.setSectionResizeMode(0, QHeaderView.ResizeMode.Fixed)
        else:
            layers_header.setResizeMode(0, QHeaderView.Fixed)
        self.layers_table.setColumnWidth(0, 30)
        self.layers_table.setAlternatingRowColors(True)
        layout.addWidget(self.layers_table)
        
        self.layers_status = QLabel(self.tr("Connect to ArcGeek Survey and/or PostgreSQL first"))
        self.layers_status.setObjectName("layersStatus")
        layout.addWidget(self.layers_status)
        
        self.tabs.addTab(tab, self.tr("Layers"))

    def apply_styles(self):
        self.setStyleSheet("""
            QDialog {
                background-color: #f8f9fa;
            }
            
            QGroupBox {
                font-weight: bold;
                border: 2px solid #dee2e6;
                border-radius: 8px;
                margin-top: 1ex;
                padding-top: 10px;
                background-color: white;
            }
            
            QGroupBox::title {
                subcontrol-origin: margin;
                left: 10px;
                padding: 0 5px 0 5px;
                color: #495057;
            }
            
            QPushButton {
                background-color: #007bff;
                border: none;
                color: white;
                padding: 8px 16px;
                border-radius: 4px;
                font-weight: 500;
                min-width: 80px;
            }
            
            QPushButton:hover {
                background-color: #0056b3;
            }
            
            QPushButton:pressed {
                background-color: #004085;
            }
            
            QPushButton:disabled {
                background-color: #6c757d;
                color: #dee2e6;
            }
            
            QPushButton#createFormBtn {
                background-color: #28a745;
                font-size: 14px;
                font-weight: bold;
            }
            
            QPushButton#createFormBtn:hover {
                background-color: #1e7e34;
            }
            
            QLineEdit, QTextEdit, QComboBox {
                border: 1px solid #ced4da;
                border-radius: 4px;
                padding: 8px;
                background-color: white;
            }
            
            QLineEdit:focus, QTextEdit:focus, QComboBox:focus {
                border-color: #80bdff;
                outline: 0;
                box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
            }
            
            QTableWidget {
                background-color: white;
                alternate-background-color: #f8f9fa;
                selection-background-color: #e3f2fd;
                gridline-color: #dee2e6;
                border: 1px solid #dee2e6;
                border-radius: 4px;
            }
            
            QTableWidget::item:disabled {
                background-color: #f8f9fa;
                color: #6c757d;
                font-style: italic;
            }
            
            QListWidget {
                background-color: white;
                alternate-background-color: #f8f9fa;
                selection-background-color: #e3f2fd;
                border: 1px solid #dee2e6;
                border-radius: 4px;
                padding: 4px;
            }
            
            QListWidget::item {
                padding: 8px;
                border-bottom: 1px solid #e9ecef;
            }
            
            QListWidget::item:selected {
                background-color: #e3f2fd;
                color: #1976d2;
            }
            
            QLabel#loginStatus[text="Connected"] {
                color: #28a745;
                font-weight: bold;
            }
            
            QLabel#loginStatus[text*="Error"] {
                color: #dc3545;
            }
            
            QLabel#postgresStatus[text*="Connected"] {
                color: #28a745;
            }
            
            QLabel#postgresStatus[text*="Error"] {
                color: #dc3545;
            }
            
            QLabel#fieldsInfo {
                font-weight: bold;
                color: #495057;
            }
            
            QLabel#creationStatus {
                font-style: italic;
                color: #6c757d;
            }
            
            QLabel#layersStatus {
                font-style: italic;
                color: #6c757d;
                padding: 10px;
            }
            
            QLabel#serverMessage {
                background-color: #f8f9fa;
                border: 1px solid #e9ecef;
                border-radius: 4px;
                padding: 8px;
                font-size: 12px;
            }
            
            QTabWidget::pane {
                border: 1px solid #dee2e6;
                background-color: white;
                border-radius: 4px;
            }
            
            QTabBar::tab {
                background-color: #e9ecef;
                border: 1px solid #dee2e6;
                padding: 8px 16px;
                margin-right: 2px;
                border-top-left-radius: 4px;
                border-top-right-radius: 4px;
            }
            
            QTabBar::tab:selected {
                background-color: white;
                border-bottom-color: white;
            }
            
            QTabBar::tab:hover {
                background-color: #f8f9fa;
            }
            
            QTextBrowser {
                background-color: #f8f9fa;
                border: 1px solid #e9ecef;
                border-radius: 4px;
                padding: 10px;
            }
        """)

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
        self.update_form_details()
        
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
        
        field_name_item = QTableWidgetItem("")
        field_name_item.setFlags(field_name_item.flags() & ~Qt.ItemFlag.ItemIsEditable)
        field_name_item.setBackground(QColor("#f8f9fa"))
        self.fields_table.setItem(row, 1, field_name_item)
        
        type_combo = QComboBox()
        type_combo.addItems(["text", "email", "number", "textarea", "date", "url"])
        type_combo.setCurrentText("text")
        self.fields_table.setCellWidget(row, 2, type_combo)
        
        required_check = QCheckBox()
        required_check.setChecked(False)
        self.fields_table.setCellWidget(row, 3, required_check)
        
        display_name_item.itemChanged = lambda: self.update_field_name(row)
        
        self.update_fields_info()
    
    def update_field_name(self, row):
        try:
            display_name_item = self.fields_table.item(row, 0)
            field_name_item = self.fields_table.item(row, 1)
            
            if not display_name_item or not field_name_item:
                return
            
            display_name = display_name_item.text().strip()
            
            if display_name:
                existing_names = []
                for i in range(self.fields_table.rowCount()):
                    if i != row:
                        existing_field_item = self.fields_table.item(i, 1)
                        if existing_field_item and existing_field_item.text():
                            existing_names.append(existing_field_item.text())
                
                field_name = self.generate_unique_field_name(display_name, existing_names)
                field_name_item.setText(field_name)
            else:
                field_name_item.setText("")
                
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
            self.fields_table.removeRow(current_row)
            self.update_fields_info()

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
            self.update_form_details()
            self.copy_url_btn.setEnabled(True)
            self.open_form_btn.setEnabled(True)
        else:
            self.selected_form = None
            self.update_form_details()
            self.copy_url_btn.setEnabled(False)
            self.open_form_btn.setEnabled(False)

    def update_form_details(self):
        if not self.selected_form:
            self.form_details.setHtml(f"<p><i>{self.tr('Select a form to view details')}</i></p>")
            return
        
        form = self.selected_form
        storage_type_text = {
            'admin_supabase': self.tr('Shared Database'),
            'user_supabase': self.tr('Your Supabase'),
            'user_postgres': self.tr('Your PostgreSQL')
        }.get(form['storage_type'], form['storage_type'])
        
        details_html = f"""
        <table style="width: 100%; font-size: 12px;">
            <tr><td><b>{self.tr('Title')}:</b></td><td>{form['title']}</td></tr>
            <tr><td><b>{self.tr('Code')}:</b></td><td>{form['form_code']}</td></tr>
            <tr><td><b>{self.tr('Responses')}:</b></td><td>{form['response_count']}/{form['max_responses']}</td></tr>
            <tr><td><b>{self.tr('Storage')}:</b></td><td>{storage_type_text}</td></tr>
            <tr><td><b>{self.tr('Created')}:</b></td><td>{form['created_at'][:10]}</td></tr>
        </table>
        <br>
        <p><b>{self.tr('Collection URL')}:</b></p>
        <p style="background: #f8f9fa; padding: 8px; border: 1px solid #dee2e6; border-radius: 4px; font-family: monospace; font-size: 11px; word-break: break-all;">
            {form['collection_url']}
        </p>
        """
        
        self.form_details.setHtml(details_html)

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
            field_name_item = self.fields_table.item(i, 1)
            type_combo = self.fields_table.cellWidget(i, 2)
            required_check = self.fields_table.cellWidget(i, 3)
            
            if not display_name_item or not display_name_item.text().strip():
                continue
            
            if not field_name_item or not field_name_item.text().strip():
                continue
                
            if not type_combo or not required_check:
                continue
            
            display_name = display_name_item.text().strip()
            field_name = field_name_item.text().strip()
            field_type = type_combo.currentText()
            is_required = required_check.isChecked()
            
            field = {
                'name': field_name,
                'type': field_type,
                'required': is_required,
                'label': display_name
            }
            fields.append(field)
        
        return fields

    def clear_form_fields(self):
        self.form_title_edit.clear()
        self.form_description_edit.clear()
        self.fields_table.setRowCount(0)
        self.update_fields_info()

    def closeEvent(self, event):
        self.save_settings()
        self.db_manager.disconnect()
        super().closeEvent(event)