"""Узкий browser regression через уже авторизованную Selenium-сессию snak1.

Запуск: python3 qa/browser_hangar_reconciliation.py
Меняет только тестовый аккаунт через кнопки SPA; снимает raw WS/DOM и screenshots.
"""
import base64
import json
import tempfile
import time
import urllib.request
from pathlib import Path


ROOT = "http://127.0.0.1:4444"
KEY = "element-6066-11e4-a52e-4f735466cecf"


def http(path, payload=None):
    body = None if payload is None else json.dumps(payload).encode()
    req = urllib.request.Request(ROOT + path, data=body,
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=30) as response:
        value = json.load(response)["value"]
    if isinstance(value, dict) and "error" in value:
        raise RuntimeError(value)
    return value


class Browser:
    def __init__(self):
        status = http("/status")
        sessions = [slot["session"]["sessionId"] for node in status["nodes"]
                    for slot in node["slots"] if slot.get("session")]
        assert len(sessions) == 1, sessions
        self.base = "/session/" + sessions[0]
        self.out = Path(tempfile.mkdtemp(prefix="hangar-browser-", dir=Path(__file__).parent))
        self.results = []

    def call(self, path, payload=None):
        return http(self.base + path, payload)

    def js(self, script, *args):
        return self.call("/execute/sync", {"script": script, "args": list(args)})

    def wait(self, predicate, timeout=30):
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            result = predicate()
            if result:
                return result
            time.sleep(.2)
        raise AssertionError("Browser condition timed out")

    def click(self, selector):
        self.wait(lambda: self.js("const e=document.querySelector(arguments[0]);"
                                 "return !!e && !!e.getClientRects().length && !e.disabled;", selector))
        element = self.call("/element", {"using": "css selector", "value": selector})[KEY]
        self.call("/element/" + element + "/click", {})

    def page(self, mode):
        self.click('div[mode="' + mode + '"]')
        self.wait(lambda: self.js("return typeof Page!=='undefined' && Page?.messageData?.data?.Page?.page===arguments[0]"
                                 " && Page?.$Content?.Show && !$('#overlay').is(':visible');", mode))
        time.sleep(.7)

    def reload(self):
        self.call("/refresh", {})
        self.wait(lambda: self.js("return typeof Page!=='undefined' && !!Page?.$Content?.Items && !!window.WS?.ws"
                                 " && WS.ws.readyState===1 && Page.$Content.Show;"))
        time.sleep(.8)
        self.listen()

    def listen(self):
        self.js("""
        window.__hangarFrames=[];
        if (WS.ws.__hangarListener) return;
        WS.ws.__hangarListener=true;
        WS.ws.addEventListener('message', event=>{
            const m=JSON.parse(event.data);
            if (!m?.data?.Page?.FleetList) return;
            window.__hangarFrames.push({requestId:m.requestId, mode:m.mode, action:m.action,
                serverTime:m.serverTime, queues:m.data.Page.QueueList,
                counts:Object.fromEntries(Object.entries(m.data.Page.FleetList).map(([id,x])=>[id,x.count]))});
        });
        """)

    def probe(self, item_id):
        return self.js("""
        const item=Page?.$Content?.Items?.[arguments[0]]?.[0];
        const queues=Object.values(Page?.$Content?.Queues?.Items||{}).map(x=>x[0]);
        return {time:GameServerTime.now(), mode:Data.mode, generation:Page.renderGeneration,
            dom:item ? Number(item.querySelector('.LevelNumber').textContent):null,
            baseline:item?.data?.count, messageCount:Page.messageData?.data?.Page?.FleetList?.[arguments[0]]?.count,
            queues:queues.map(q=>({qid:q.data.qid,count:q.data.count,status:q.data.status,
                start:q.data.start_time,end:q.data.end_time,time:q.data.time,ticking:q.EventActive})),
            raw:window.__hangarFrames?.at(-1)||null,
            retired:(window.__retiredHangar||[]).map(x=>({connected:x.isConnected,ticking:x.EventActive,
                simulation:!!x._hangarSimulation}))};
        """, item_id)

    def shot(self, name):
        self.js("window.scrollTo(0,0); document.activeElement?.blur();")
        self.out.joinpath(name + ".png").write_bytes(base64.b64decode(self.call("/screenshot")))

    def record(self, name, **values):
        record = {"test": name, **values}
        self.results.append(record)
        self.out.joinpath("results.json").write_text(json.dumps(self.results, ensure_ascii=False, indent=2))
        print(json.dumps(record, ensure_ascii=False), flush=True)

    def build(self, item_id, count):
        assert not self.probe(item_id)["queues"], "QA requires empty hangar"
        element = self.call("/element", {"using": "css selector",
                            "value": f"#builds{item_id} input[name=buildCount]"})[KEY]
        self.call("/element/" + element + "/clear", {})
        self.call("/element/" + element + "/value", {"text": str(count)})
        # Кнопка появляется на GameTick и проходит fadeIn.
        time.sleep(1.3)
        self.click('input.btn-success[value="Строить"]')
        self.wait(lambda: self.probe(item_id)["queues"])

    def cancel(self, item_id):
        self.js("window.__retiredHangar=Object.values(Page.$Content.Queues.Items).map(x=>x[0]);")
        self.click(f"#queues{item_id} .btn-box-cancel")
        self.wait(lambda: not self.probe(item_id)["queues"])
        time.sleep(.5)

    def fixtures(self):
        result = self.js("""
        const checks=[];
        const check=(name,value)=>{if(!value) throw Error(name);checks.push(name);};
        const item=document.createElement('game-build');
        item.render=()=>{};
        const page={renderGeneration:1,pageActive:true,
            isRenderCurrent(g){return this.pageActive&&this.renderGeneration===g;}};
        const originalNow=GameServerTime.now;
        let now=110;
        GameServerTime.now=()=>now;
        try {
            const data=Object.freeze({id:212,count:0});
            const q=Object.freeze({id:212,qid:1,count:5,time:10,start_time:100,end_time:150,status:'queued'});
            item.applyHangarSnapshot(data,{1:q},page);
            check('zero baseline stays immutable',item.getHangarDisplayCount()===1&&data.count===0&&item.data.count===0);
            check('repeated tick is idempotent',item.getHangarDisplayCount()===1);
            page.renderGeneration++;
            item.applyHangarSnapshot({id:212,count:1},{1:{...q,count:4,start_time:110}},page);
            check('same qid server progress replaces delta',item.getHangarDisplayCount()===1);
            now=120;
            check('simulation resumes from fresh snapshot',item.getHangarDisplayCount()===2&&item.data.count===1);
            item.applyHangarSnapshot({id:212,count:2},{},page);
            now=200;
            check('cancel removes old progress',item.getHangarDisplayCount()===2);
            item.applyHangarSnapshot({id:212,count:2},{2:{...q,qid:2,count:3,start_time:200,end_time:230}},page);
            now=230;
            check('last unit visually completes',item.getHangarDisplayCount()===5);
            item.applyHangarSnapshot({id:212,count:5},{},page);
            check('completion is not counted twice',item.getHangarDisplayCount()===5);
            item.applyHangarSnapshot({id:212,count:5},{3:{...q,start_time:230,end_time:280}},page);
            now=240;
            check('new queue uses new baseline',item.getHangarDisplayCount()===6);
            page.renderGeneration++;
            check('superseded generation cannot simulate',item.getHangarDisplayCount()===5);
            item.applyHangarSnapshot({id:212,count:40},{},page);
            check('other planet snapshot replaces count',item.getHangarDisplayCount()===40);
            // Общие listeners страницы проверяются уже с реальными часами.
            GameServerTime.now=originalNow;
            let ticks=0;
            item.render=()=>ticks++;
            document.body.append(item);
            item.startTicker();item.startTicker();
            ticks=0;document.dispatchEvent(new Event('GameTick'));
            check('one listener per element',ticks===1);
            item.stopTicker();ticks=0;document.dispatchEvent(new Event('GameTick'));
            check('stop is immediate before fadeOut',ticks===0);
            item.startTicker();item.remove();ticks=0;document.dispatchEvent(new Event('GameTick'));
            check('disconnect cleans listener and simulation',ticks===0&&!item.EventActive&&!item._hangarSimulation);
            return checks;
        } finally {GameServerTime.now=originalNow;item.remove();}
        """)
        self.record("isolated-browser-fixtures", checks=result)

    def cancel_test(self, mode, item_id):
        self.page(mode)
        before = self.probe(item_id)
        self.listen()
        self.build(item_id, 5)
        self.wait(lambda: self.probe(item_id)["dom"] >= before["dom"] + 1, timeout=35)
        simulated = self.probe(item_id)
        assert simulated["baseline"] == before["baseline"] == simulated["messageCount"]
        self.shot(mode + "-simulated")
        self.cancel(item_id)
        after = self.probe(item_id)
        expected = after["raw"]["counts"][str(item_id)]
        assert expected == before["dom"] + 1, (expected, before)
        assert after["dom"] == after["baseline"] == after["messageCount"] == expected, after
        assert all(not x["ticking"] for x in after["retired"]), after
        time.sleep(4)
        delayed = self.probe(item_id)
        assert delayed["dom"] == expected, delayed
        self.shot(mode + "-cancel")
        frames = self.js("return window.__hangarFrames;")
        self.reload()
        reloaded = self.probe(item_id)
        assert reloaded["dom"] == expected, reloaded
        self.record(mode + "-cancel", before=before, simulated=simulated, after=after,
                    delayed=delayed, reloaded=reloaded, frames=frames)

    def completion_test(self):
        self.page("shipyard")
        before = self.probe(212)
        self.listen()
        self.build(212, 3)
        samples = []

        def complete():
            p = self.probe(212)
            if not samples or (p["dom"],len(p["queues"])) != (samples[-1]["dom"],len(samples[-1]["queues"])):
                samples.append(p)
            return not p["queues"]

        self.wait(complete, timeout=65)
        after = self.probe(212)
        expected = before["dom"] + 3
        assert after["dom"] == after["raw"]["counts"]["212"] == expected, after
        counts = [s["dom"] for s in samples]
        assert counts == sorted(counts) and max(counts) == expected, counts
        assert before["dom"]+1 in counts and before["dom"]+2 in counts, counts
        time.sleep(4)
        delayed = self.probe(212)
        assert delayed["dom"] == expected
        self.shot("shipyard-completed")
        self.record("full-completion-new-queue", before=before,samples=samples,after=after,delayed=delayed)

    def navigation_test(self):
        self.build(212, 5)
        self.wait(lambda: self.probe(212)["dom"] > self.probe(212)["baseline"], timeout=35)
        self.js("window.__retiredHangar=[...Object.values(Page.$Content.Items),...Object.values(Page.$Content.Queues.Items)].map(x=>x[0]);")
        self.page("buildings")
        retired=self.js("return window.__retiredHangar.map(x=>({connected:x.isConnected,ticking:x.EventActive,simulation:!!x._hangarSimulation}));")
        assert all(not x["connected"] and not x["ticking"] and not x["simulation"] for x in retired), retired
        self.page("shipyard")
        returned=self.probe(212)
        assert returned["baseline"] == returned["raw"]["counts"]["212"], returned
        self.cancel(212)
        after=self.probe(212)
        assert after["dom"] == after["raw"]["counts"]["212"], after
        self.record("page-navigation", retired=retired,returned=returned,after=after)


if __name__ == "__main__":
    browser=Browser()
    print("Artifacts: " + str(browser.out), flush=True)
    browser.reload()
    assert browser.js("return document.querySelector('#planets-select')?.value;") == "20", "Expected snak1 QA planet 20"
    browser.fixtures()
    browser.cancel_test("shipyard",212)
    browser.cancel_test("defense",401)
    browser.completion_test()
    browser.navigation_test()
    print("PASS: browser hangar reconciliation", flush=True)
