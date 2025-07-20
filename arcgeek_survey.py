import os
try:
    from qgis.PyQt.QtCore import QTranslator, QCoreApplication, QSettings, QLocale
    from qgis.PyQt.QtGui import QIcon
    from qgis.PyQt.QtWidgets import QAction
except ImportError:
    try:
        from PyQt6.QtCore import QTranslator, QCoreApplication, QSettings, QLocale
        from PyQt6.QtGui import QIcon, QAction
    except ImportError:
        from PyQt5.QtCore import QTranslator, QCoreApplication, QSettings, QLocale
        from PyQt5.QtGui import QIcon
        from PyQt5.QtWidgets import QAction

from qgis.core import QgsProject

from .main_dialog import ArcGeekDialog


class ArcGeekSurveyPlugin:
    def __init__(self, iface):
        self.iface = iface
        self.plugin_dir = os.path.dirname(__file__)
        self.actions = []
        self.menu = 'ArcGeek Survey'
        self.translator = None
        
        self.load_translations()

    def load_translations(self):
        """Carga las traducciones del plugin"""
        try:
            i18n_dir = os.path.join(self.plugin_dir, 'i18n')
            
            # Obtener el idioma de QGIS
            settings = QSettings()
            locale = settings.value('locale/userLocale', QLocale.system().name())
            
            if locale:
                locale = locale[:2]  # Solo el código de idioma (es, en, fr, etc.)
            else:
                locale = 'en'
            
            # Buscar archivo de traducción
            qm_file = os.path.join(i18n_dir, f'arcgeek_survey_{locale}.qm')
            
            if os.path.exists(qm_file):
                self.translator = QTranslator()
                if self.translator.load(qm_file):
                    QCoreApplication.installTranslator(self.translator)
                    print(f"ArcGeek Survey Plugin: Loaded translation for {locale}")
                    return True
                else:
                    print(f"ArcGeek Survey Plugin: Failed to load translation {qm_file}")
            else:
                print(f"ArcGeek Survey Plugin: Translation file not found: {qm_file}")
                
        except Exception as e:
            print(f"ArcGeek Survey Plugin: Error loading translations: {e}")
        
        return False

    def tr(self, message):
        return QCoreApplication.translate('ArcGeekSurvey', message)

    def add_action(self, icon_path, text, callback, enabled_flag=True, 
                   add_to_menu=True, add_to_toolbar=False, status_tip=None,
                   whats_this=None, parent=None):
        
        icon = QIcon(icon_path)
        action = QAction(icon, text, parent)
        action.triggered.connect(callback)
        action.setEnabled(enabled_flag)
        
        if status_tip is not None:
            action.setStatusTip(status_tip)
        
        if whats_this is not None:
            action.setWhatsThis(whats_this)
        
        if add_to_menu:
            self.iface.addPluginToWebMenu(self.menu, action)
        
        self.actions.append(action)
        return action

    def initGui(self):
        icon_path = os.path.join(self.plugin_dir, 'icon.png')
        self.add_action(
            icon_path,
            text=self.tr('ArcGeek Survey'),
            callback=self.run,
            parent=self.iface.mainWindow(),
            add_to_menu=True,
            add_to_toolbar=False,
            status_tip=self.tr('Create and manage georeferenced surveys'),
            whats_this=self.tr('ArcGeek Survey Plugin - Create georeferenced forms and manage spatial data')
        )

    def unload(self):
        for action in self.actions:
            self.iface.removePluginWebMenu(self.menu, action)
        
        if self.translator:
            QCoreApplication.removeTranslator(self.translator)

    def run(self):
        dialog = ArcGeekDialog(self)
        dialog.show()
        try:
            result = dialog.exec()
        except AttributeError:
            result = dialog.exec()

    def get_settings_value(self, key, default=None):
        settings = QSettings()
        settings.beginGroup('ArcGeekSurvey')
        value = settings.value(key, default)
        settings.endGroup()
        return value

    def set_settings_value(self, key, value):
        settings = QSettings()
        settings.beginGroup('ArcGeekSurvey')
        settings.setValue(key, value)
        settings.endGroup()