"""Соответствие существующих сценариев именованным операциям v2; не wire v1."""
import re

CATALOG = {
  "queries": {
    "player.planets.get": [
      "overview",
      "",
      []
    ],
    "player.status.get": [
      "overview",
      "",
      []
    ],
    "localization.get": [
      "lang",
      "get",
      [
        "lang"
      ]
    ],
    "overview.get": [
      "overview",
      "",
      []
    ],
    "buildings.get": [
      "buildings",
      "",
      []
    ],
    "research.get": [
      "researchs",
      "",
      []
    ],
    "shipyard.get": [
      "shipyard",
      "",
      []
    ],
    "defense.get": [
      "defense",
      "",
      []
    ],
    "galaxy.system.get": [
      "galaxy",
      "",
      [
        "galaxy",
        "system"
      ]
    ],
    "fleets.get": [
      "fleets",
      "",
      [
        "target"
      ]
    ],
    "fleet.missions.get": [
      "fleets",
      "GetAvailableMissions",
      [
        "fleetId",
        "coordinates",
        "speedFactor",
        "missionType",
        "forPlan",
        "planReturn",
        "origin",
        "duration",
        "mining"
      ]
    ],
    "fleet.navigation.get": [
      "fleets",
      "GetSystemNavigationInfo",
      [
        "galaxy",
        "system",
        "fleetId",
        "coordinates"
      ]
    ],
    "fleet.commands.get": [
      "fleets",
      "GetFleetCommands",
      [
        "fleet_id",
        "fleetId"
      ]
    ],
    "fleet.missions.catalog.get": [
      "fleets",
      "GetMissions",
      []
    ],
    "messages.get": [
      "messages",
      "",
        ["page", "category"]
    ],
    "friends.get": [
      "friends",
      "list",
      []
    ],
    "information.get": [
      "information",
      "get",
      [
        "id"
      ]
    ],
    "combat.report.get": [
      "battlereport",
      "get",
      [
        "id"
      ]
    ],
    "combat.reports.get": [
      "battlereport",
      "list",
      [
        "page"
      ]
    ]
  },
  "commands": {
    "planet.select": [
      "overview",
      "planetselect",
      []
    ],
    "planet.rename": [
      "overview",
      "RenamePlanet",
      [
        "name"
      ]
    ],
    "building.upgrade": [
      "buildings",
      "build",
      [
        "id"
      ]
    ],
    "building.dismantle": [
      "buildings",
      "dismantle",
      [
        "id"
      ]
    ],
    "building.cancel": [
      "buildings",
      "cancel",
      [
        "id"
      ]
    ],
    "research.start": [
      "researchs",
      "build",
      [
        "id"
      ]
    ],
    "research.cancel": [
      "researchs",
      "cancel",
      [
        "id"
      ]
    ],
    "shipyard.build": [
      "shipyard",
      "build",
      [
        "items"
      ]
    ],
    "shipyard.cancel": [
      "shipyard",
      "cancel",
      [
        "id"
      ]
    ],
    "defense.build": [
      "defense",
      "build",
      [
        "items"
      ]
    ],
    "defense.cancel": [
      "defense",
      "cancel",
      [
        "id"
      ]
    ],
    "phalanx.scan": [
      "galaxy",
      "PhalanxScan",
      [
        "targetId",
        "scanToken"
      ]
    ],
    "fleet.create": [
      "fleets",
      "toOrbit",
      [
        "items"
      ]
    ],
    "fleet.ships.transfer": [
      "fleets",
      "MoveShip",
      [
        "fromFleetId",
        "toFleetId",
        "shipId",
        "count"
      ]
    ],
    "fleet.disband": [
      "fleets",
      "DisbandFleet",
      [
        "FleetId"
      ]
    ],
    "fleet.rename": [
      "fleets",
      "RenameFleet",
      [
        "FleetId",
        "Name"
      ]
    ],
    "fleet.send": [
      "fleets",
      "SendMission",
      [
        "fleetId",
        "missionType",
        "coordinates",
        "speedFactor",
        "fuel",
        "resources",
        "duration",
        "group",
        "refuel",
        "contactLoss",
        "mining"
      ]
    ],
    "fleet.recall": [
      "fleets",
      "RecallFleet",
      [
        "fleetId"
      ]
    ],
    "fleet.stop": [
      "fleets",
      "StopFleet",
      [
        "fleetId"
      ]
    ],
    "fleet.resume": [
      "fleets",
      "ResumeFleet",
      [
        "fleetId",
        "useCargoFuel",
        "fuelMode"
      ]
    ],
    "fleet.route.set": [
      "fleets",
      "SetFleetCommands",
      [
        "fleetId",
        "plan"
      ]
    ],
    "fleet.sensors.set": [
      "fleets",
      "SetFleetSensors",
      [
        "fleetId",
        "enabled"
      ]
    ],
    "fleet.group.invite": [
      "fleets",
      "InviteAttackGroup",
      [
        "groupId",
        "username"
      ]
    ],
    "messages.read": [
      "messages",
      "read",
      [
        "ReadId"
      ]
    ],
    "messages.delete": [
      "messages",
      "delete",
      [
        "Ids"
      ]
    ],
    "friend.request": [
      "friends",
      "request",
      [
        "id",
        "text"
      ]
    ],
    "friend.accept": [
      "friends",
      "accept",
      [
        "id"
      ]
    ],
    "friend.remove": [
      "friends",
      "remove",
      [
        "id"
      ]
    ],
    "combat.report.save": [
      "battlereport",
      "save",
      [
        "id"
      ]
    ],
    "combat.report.unsave": [
      "battlereport",
      "unsave",
      [
        "id"
      ]
    ]
  }
}


def ids(value, outgoing=False, key=''):
    if isinstance(value,dict): return {k:ids(v,outgoing,k) for k,v in value.items()}
    if isinstance(value,list): return [ids(v,outgoing,key) for v in value]
    if re.fullmatch(r'(id|qid|[A-Za-z_]*_id|[A-Za-z_]*Id|Ids|ReadId|current_planet|main_planet)',key):
        if outgoing and type(value) is int and value>=0:return str(value)
        if not outgoing and isinstance(value,str) and re.fullmatch(r'0|[1-9][0-9]*',value):return int(value)
    return value


def resolve(mode,action='',data=None):
    data=dict(data or {})
    if action=='planetselect':return 'command','planet.select',{},str(data['planetId'])
    if mode=='overview' and action=='RenamePlanet':data={'name':data.get('Name')}
    if action=='RecallDefend':action='RecallFleet'
    for name,(m,a,_) in CATALOG['commands'].items():
        if (m,a)==(mode,action):return 'command',name,ids(data,True),None
    if action in ('','ActualData','reconnect','list') and mode=='friends':action='list'
    if not action and mode in ('battlereport','information'):action='get'
    if action in ('ActualData','reconnect','list') and mode not in ('friends','battlereport'):action=''
    if mode=='overview' and not action:return 'query','overview.get',ids(data,True),None
    for name,(m,a,_) in CATALOG['queries'].items():
        if (m,a)==(mode,action):return 'query',name,ids(data,True),None
    raise ValueError('Операция не реализована')
