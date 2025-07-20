try:
    from qgis.PyQt.QtCore import pyqtSignal, pyqtSlot, QObject, Qt
    from qgis.PyQt.QtWidgets import *
    from qgis.PyQt.QtGui import QIcon, QFont, QColor
    QT_VERSION = 'PyQt5/6'
except ImportError:
    try:
        from PyQt6.QtCore import pyqtSignal, pyqtSlot, QObject, Qt
        from PyQt6.QtWidgets import *
        from PyQt6.QtGui import QIcon, QFont, QColor, QAction
        QT_VERSION = 'PyQt6'
    except ImportError:
        from PyQt5.QtCore import pyqtSignal, pyqtSlot, QObject, Qt
        from PyQt5.QtWidgets import *
        from PyQt5.QtGui import QIcon, QFont, QColor
        QT_VERSION = 'PyQt5'

def get_qt_version():
    return QT_VERSION