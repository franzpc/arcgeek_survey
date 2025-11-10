import os
import sys
import requests
import json

try:
    from qgis.PyQt.QtCore import Qt, pyqtSlot, QT_VERSION_STR, QTimer, QCoreApplication
    from qgis.PyQt.QtWidgets import (
        QVBoxLayout, QHBoxLayout, QTabWidget, QWidget,
        QLabel, QLineEdit, QPushButton, QTextEdit, QTableWidget,
        QTableWidgetItem, QComboBox, QCheckBox, QSpinBox,
        QGroupBox, QMessageBox, QProgressBar, QListWidget,
        QListWidgetItem, QSplitter, QFrame, QApplication, QHeaderView,
        QStackedWidget, QFormLayout, QTextBrowser, QScrollArea,
        QGridLayout, QDialog, QDialogButtonBox
    )
    from qgis.PyQt.QtGui import QFont, QPixmap, QIcon, QColor
    QT6_MODE = QT_VERSION_STR.startswith('6')
except ImportError:
    try:
        from PyQt6.QtCore import Qt, pyqtSlot, QTimer, QCoreApplication
        from PyQt6.QtWidgets import (
            QVBoxLayout, QHBoxLayout, QTabWidget, QWidget,
            QLabel, QLineEdit, QPushButton, QTextEdit, QTableWidget,
            QTableWidgetItem, QComboBox, QCheckBox, QSpinBox,
            QGroupBox, QMessageBox, QProgressBar, QListWidget,
            QListWidgetItem, QSplitter, QFrame, QApplication, QHeaderView,
            QStackedWidget, QFormLayout, QTextBrowser, QScrollArea,
            QGridLayout, QDialog, QDialogButtonBox
        )
        from PyQt6.QtGui import QFont, QPixmap, QIcon, QColor
        QT6_MODE = True
    except ImportError:
        from PyQt5.QtCore import Qt, pyqtSlot, QTimer, QCoreApplication
        from PyQt5.QtWidgets import (
            QVBoxLayout, QHBoxLayout, QTabWidget, QWidget,
            QLabel, QLineEdit, QPushButton, QTextEdit, QTableWidget,
            QTableWidgetItem, QComboBox, QCheckBox, QSpinBox,
            QGroupBox, QMessageBox, QProgressBar, QListWidget,
            QListWidgetItem, QSplitter, QFrame, QApplication, QHeaderView,
            QStackedWidget, QFormLayout, QTextBrowser, QScrollArea,
            QGridLayout, QDialog, QDialogButtonBox
        )
        from PyQt5.QtGui import QFont, QPixmap, QIcon, QColor
        QT6_MODE = False

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

class OptionsDialog(QDialog):
    def __init__(self, parent, field_name, options=None):
        super().__init__(parent)
        self.setWindowTitle(f"Options for {field_name}")
        self.setModal(True)
        self.setMinimumSize(400, 300)
        self.options = options or []
        
        layout = QVBoxLayout(self)
        
        label = QLabel("Enter options (one per line):")
        layout.addWidget(label)
        
        self.options_edit = QTextEdit()
        self.options_edit.setPlainText('\n'.join(self.options))
        layout.addWidget(self.options_edit)
        
        buttons = QDialogButtonBox(QDialogButtonBox.StandardButton.Ok | QDialogButtonBox.StandardButton.Cancel)
        buttons.accepted.connect(self.accept)
        buttons.rejected.connect(self.reject)
        layout.addWidget(buttons)
    
    def get_options(self):
        text = self.options_edit.toPlainText().strip()
        if not text:
            return []
        options = [opt.strip() for opt in text.split('\n') if opt.strip()]
        return options

class UIComponents:
    def __init__(self, parent_dialog):
        self.parent = parent_dialog
        
    def tr(self, text):
        return QCoreApplication.translate('ArcGeekSurvey', text)
        
    def create_config_tab(self):
        tab = QWidget()
        scroll = QScrollArea()
        scroll.setWidgetResizable(True)
        scroll.setWidget(tab)
        
        layout = QVBoxLayout(tab)
        layout.setSpacing(20)
        
        login_group = QGroupBox(self.tr("ArcGeek Survey Connection"))
        login_layout = QFormLayout(login_group)
        login_layout.setSpacing(12)
        
        self.parent.server_url_edit = QLineEdit("https://acolita.com/survey")
        self.parent.server_url_edit.setVisible(False)
        
        self.parent.email_edit = QLineEdit()
        self.parent.email_edit.setPlaceholderText(self.tr("Enter your email"))
        login_layout.addRow(self.tr("Email:"), self.parent.email_edit)
        
        self.parent.password_edit = QLineEdit()
        self.parent.password_edit.setEchoMode(get_qt_enum('Password'))
        self.parent.password_edit.setPlaceholderText(self.tr("Enter your password"))
        login_layout.addRow(self.tr("Password:"), self.parent.password_edit)
        
        login_buttons = QHBoxLayout()
        self.parent.login_btn = QPushButton(self.tr("Connect"))
        self.parent.login_btn.clicked.connect(self.parent.on_login)
        self.parent.login_btn.setMinimumHeight(35)
        login_buttons.addWidget(self.parent.login_btn)
        
        self.parent.remember_me_check = QCheckBox(self.tr("Remember credentials"))
        self.parent.remember_me_check.setChecked(True)
        login_buttons.addWidget(self.parent.remember_me_check)
        login_buttons.addStretch()
        
        login_layout.addRow("", login_buttons)
        
        self.parent.login_status = QLabel(self.tr("Not connected"))
        self.parent.login_status.setObjectName("loginStatus")
        login_layout.addRow(self.tr("Status:"), self.parent.login_status)
        
        links_layout = QHBoxLayout()
        self.parent.register_link = QLabel(f'<a href="#" style="color: #3498db;">{self.tr("Create Account")}</a>')
        self.parent.register_link.setOpenExternalLinks(False)
        self.parent.register_link.linkActivated.connect(self.parent.on_register_link_clicked)
        links_layout.addWidget(self.parent.register_link)
        
        self.parent.forgot_link = QLabel(f'<a href="https://acolita.com/survey/auth/forgot-password.php" style="color: #3498db;">{self.tr("Forgot Password?")}</a>')
        self.parent.forgot_link.setOpenExternalLinks(True)
        links_layout.addWidget(self.parent.forgot_link)
        links_layout.addStretch()
        
        login_layout.addRow("", links_layout)
        layout.addWidget(login_group)
        
        info_layout = QHBoxLayout()
        
        user_group = QGroupBox(self.tr("User Information"))
        user_layout = QFormLayout(user_group)
        
        self.parent.user_name_label = QLabel(self.tr("Not logged in"))
        user_layout.addRow(self.tr("Name:"), self.parent.user_name_label)
        
        self.parent.user_plan_label = QLabel(self.tr("Unknown"))
        user_layout.addRow(self.tr("Plan:"), self.parent.user_plan_label)
        
        self.parent.user_storage_label = QLabel(self.tr("Not configured"))
        user_layout.addRow(self.tr("Storage:"), self.parent.user_storage_label)
        
        info_layout.addWidget(user_group)
        
        db_group = QGroupBox(self.tr("PostgreSQL Database"))
        db_layout = QFormLayout(db_group)
        
        self.parent.postgres_status_label = QLabel(self.tr("Not configured"))
        self.parent.postgres_status_label.setObjectName("postgresStatus")
        db_layout.addRow(self.tr("Status:"), self.parent.postgres_status_label)
        
        self.parent.postgres_config_label = QLabel(self.tr("None"))
        db_layout.addRow(self.tr("Configuration:"), self.parent.postgres_config_label)
        
        db_buttons = QHBoxLayout()
        self.parent.test_postgres_btn = QPushButton(self.tr("Test Connection"))
        self.parent.test_postgres_btn.clicked.connect(self.parent.on_test_postgres)
        self.parent.test_postgres_btn.setEnabled(False)
        db_buttons.addWidget(self.parent.test_postgres_btn)
        
        self.parent.configure_web_btn = QPushButton(self.tr("Configure Online"))
        self.parent.configure_web_btn.clicked.connect(self.parent.on_configure_web)
        db_buttons.addWidget(self.parent.configure_web_btn)
        
        db_layout.addRow("", db_buttons)
        
        info_layout.addWidget(db_group)
        layout.addLayout(info_layout)
        
        message_group = QGroupBox(self.tr("Server Message"))
        message_layout = QVBoxLayout(message_group)
        
        self.parent.server_message_label = QLabel(self.tr("No messages"))
        self.parent.server_message_label.setObjectName("serverMessage")
        self.parent.server_message_label.setWordWrap(True)
        self.parent.server_message_label.setStyleSheet("color: #6c757d; font-style: italic; padding: 5px;")
        message_layout.addWidget(self.parent.server_message_label)
        
        layout.addWidget(message_group)
        
        layout.addStretch()
        return scroll

    def create_forms_tab(self):
        tab = QWidget()
        layout = QVBoxLayout(tab)
        layout.setSpacing(15)
        
        top_layout = QGridLayout()
        top_layout.setColumnStretch(0, 60)
        top_layout.setColumnStretch(1, 40)
        
        form_info_group = QGroupBox("🚀 " + self.tr("Create New Form"))
        form_info_layout = QVBoxLayout(form_info_group)
        form_info_layout.setSpacing(10)
        
        title_layout = QFormLayout()
        self.parent.form_title_edit = QLineEdit()
        self.parent.form_title_edit.setPlaceholderText(self.tr("Enter form title"))
        title_layout.addRow(self.tr("Title:"), self.parent.form_title_edit)
        
        self.parent.form_description_edit = QTextEdit()
        self.parent.form_description_edit.setMaximumHeight(60)
        self.parent.form_description_edit.setPlaceholderText(self.tr("Optional description"))
        title_layout.addRow(self.tr("Description:"), self.parent.form_description_edit)
        form_info_layout.addLayout(title_layout)
        
        self.parent.form_creation_status = QLabel(self.tr("Login required"))
        self.parent.form_creation_status.setObjectName("creationStatus")
        form_info_layout.addWidget(self.parent.form_creation_status)
        
        self.parent.create_form_btn = QPushButton(self.tr("Create Form"))
        self.parent.create_form_btn.clicked.connect(self.parent.on_create_form)
        self.parent.create_form_btn.setEnabled(False)
        self.parent.create_form_btn.setMinimumHeight(40)
        self.parent.create_form_btn.setObjectName("createFormBtn")
        form_info_layout.addWidget(self.parent.create_form_btn)
        
        top_layout.addWidget(form_info_group, 0, 0)
        
        forms_group = QGroupBox("📚 " + self.tr("Your Forms"))
        forms_layout = QVBoxLayout(forms_group)
        
        forms_header = QHBoxLayout()
        self.parent.refresh_forms_btn = QPushButton(self.tr("Refresh"))
        self.parent.refresh_forms_btn.clicked.connect(self.parent.on_refresh_forms)
        self.parent.refresh_forms_btn.setEnabled(False)
        self.parent.refresh_forms_btn.setIcon(QIcon(":/images/themes/default/mActionRefresh.svg"))
        forms_header.addWidget(self.parent.refresh_forms_btn)
        
        self.parent.manage_forms_btn = QPushButton(self.tr("Manage Online"))
        self.parent.manage_forms_btn.clicked.connect(self.parent.on_manage_forms_web)
        self.parent.manage_forms_btn.setEnabled(False)
        forms_header.addWidget(self.parent.manage_forms_btn)
        
        forms_header.addStretch()
        forms_layout.addLayout(forms_header)
        
        self.parent.forms_list = QListWidget()
        self.parent.forms_list.setAlternatingRowColors(True)
        self.parent.forms_list.setMaximumHeight(120)
        self.parent.forms_list.currentItemChanged.connect(self.parent.on_form_selection_changed)
        forms_layout.addWidget(self.parent.forms_list)
        
        form_actions = QHBoxLayout()
        self.parent.copy_url_btn = QPushButton(self.tr("Copy URL"))
        self.parent.copy_url_btn.clicked.connect(self.parent.on_copy_form_url)
        self.parent.copy_url_btn.setEnabled(False)
        form_actions.addWidget(self.parent.copy_url_btn)
        
        self.parent.open_form_btn = QPushButton(self.tr("Open in Browser"))
        self.parent.open_form_btn.clicked.connect(self.parent.on_open_form_browser)
        self.parent.open_form_btn.setEnabled(False)
        form_actions.addWidget(self.parent.open_form_btn)
        
        self.parent.view_results_btn = QPushButton(self.tr("View Results"))
        self.parent.view_results_btn.clicked.connect(self.parent.on_view_results)
        self.parent.view_results_btn.setEnabled(False)
        form_actions.addWidget(self.parent.view_results_btn)
        
        form_actions.addStretch()
        forms_layout.addLayout(form_actions)
        
        top_layout.addWidget(forms_group, 0, 1)
        layout.addLayout(top_layout)
        
        fields_group = QGroupBox("⚙️ " + self.tr("Form Fields"))
        fields_layout = QVBoxLayout(fields_group)
        
        fields_header = QHBoxLayout()
        self.parent.fields_info_label = QLabel(self.tr("Fields: 0/15"))
        self.parent.fields_info_label.setObjectName("fieldsInfo")
        fields_header.addWidget(self.parent.fields_info_label)
        fields_header.addStretch()
        
        self.parent.add_field_btn = QPushButton(self.tr("Add Field"))
        self.parent.add_field_btn.clicked.connect(self.parent.on_add_field)
        self.parent.add_field_btn.setIcon(QIcon(":/images/themes/default/symbologyAdd.svg"))
        fields_header.addWidget(self.parent.add_field_btn)
        
        self.parent.remove_field_btn = QPushButton(self.tr("Remove"))
        self.parent.remove_field_btn.clicked.connect(self.parent.on_remove_field)
        self.parent.remove_field_btn.setIcon(QIcon(":/images/themes/default/symbologyRemove.svg"))
        fields_header.addWidget(self.parent.remove_field_btn)
        
        fields_layout.addLayout(fields_header)
        
        self.parent.fields_table = QTableWidget(0, 5)
        self.parent.fields_table.setHorizontalHeaderLabels([
            self.tr("Display Name"), 
            self.tr("Type"), 
            self.tr("Options"),
            self.tr("Required"),
            self.tr("Actions")
        ])
        
        header = self.parent.fields_table.horizontalHeader()
        header.setStretchLastSection(False)
        if hasattr(header, 'setSectionResizeMode'):
            header.setSectionResizeMode(0, get_qt_enum('Stretch'))
            header.setSectionResizeMode(1, get_qt_enum('ResizeToContents'))
            header.setSectionResizeMode(2, get_qt_enum('ResizeToContents'))
            header.setSectionResizeMode(3, get_qt_enum('ResizeToContents'))
            header.setSectionResizeMode(4, get_qt_enum('ResizeToContents'))
        else:
            header.setResizeMode(0, get_qt_enum('Stretch'))
            header.setResizeMode(1, get_qt_enum('ResizeToContents'))
            header.setResizeMode(2, get_qt_enum('ResizeToContents'))
            header.setResizeMode(3, get_qt_enum('ResizeToContents'))
            header.setResizeMode(4, get_qt_enum('ResizeToContents'))
        
        self.parent.fields_table.setAlternatingRowColors(True)
        self.parent.fields_table.setSelectionBehavior(QTableWidget.SelectionBehavior.SelectRows)
        self.parent.fields_table.itemChanged.connect(self.parent.on_fields_table_item_changed)
        fields_layout.addWidget(self.parent.fields_table)
        
        layout.addWidget(fields_group)
        
        return tab

    def create_layers_tab(self):
        tab = QWidget()
        layout = QVBoxLayout(tab)
        layout.setSpacing(15)
        
        controls_group = QGroupBox(self.tr("Layer Operations"))
        controls_layout = QHBoxLayout(controls_group)
        
        self.parent.refresh_layers_btn = QPushButton(self.tr("Refresh Layers"))
        self.parent.refresh_layers_btn.clicked.connect(self.parent.on_refresh_layers)
        self.parent.refresh_layers_btn.setEnabled(False)
        self.parent.refresh_layers_btn.setIcon(QIcon(":/images/themes/default/mActionRefresh.svg"))
        controls_layout.addWidget(self.parent.refresh_layers_btn)
        
        self.parent.add_selected_btn = QPushButton(self.tr("Add Selected"))
        self.parent.add_selected_btn.clicked.connect(self.parent.on_add_selected_layers)
        self.parent.add_selected_btn.setEnabled(False)
        self.parent.add_selected_btn.setIcon(QIcon(":/images/themes/default/mActionAddLayer.svg"))
        controls_layout.addWidget(self.parent.add_selected_btn)
        
        self.parent.add_all_survey_btn = QPushButton(self.tr("Add All Survey Layers"))
        self.parent.add_all_survey_btn.clicked.connect(self.parent.on_add_all_survey_layers)
        self.parent.add_all_survey_btn.setEnabled(False)
        controls_layout.addWidget(self.parent.add_all_survey_btn)
        
        controls_layout.addStretch()
        layout.addWidget(controls_group)
        
        self.parent.layers_table = QTableWidget(0, 7)
        self.parent.layers_table.setHorizontalHeaderLabels([
            "", 
            self.tr("Form Title"),
            self.tr("Source"), 
            self.tr("Schema"), 
            self.tr("Table"), 
            self.tr("Geometry"), 
            self.tr("Type")
        ])
        
        layers_header = self.parent.layers_table.horizontalHeader()
        layers_header.setStretchLastSection(True)
        if hasattr(layers_header, 'setSectionResizeMode'):
            layers_header.setSectionResizeMode(0, QHeaderView.ResizeMode.Fixed)
        else:
            layers_header.setResizeMode(0, QHeaderView.Fixed)
        self.parent.layers_table.setColumnWidth(0, 30)
        self.parent.layers_table.setAlternatingRowColors(True)
        layout.addWidget(self.parent.layers_table)
        
        self.parent.layers_status = QLabel(self.tr("Connect to ArcGeek Survey and/or PostgreSQL first"))
        self.parent.layers_status.setObjectName("layersStatus")
        layout.addWidget(self.parent.layers_status)
        
        return tab

    def apply_styles(self, dialog):
        dialog.setStyleSheet("""
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