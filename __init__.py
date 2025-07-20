def classFactory(iface):
    from .arcgeek_survey import ArcGeekSurveyPlugin
    return ArcGeekSurveyPlugin(iface)