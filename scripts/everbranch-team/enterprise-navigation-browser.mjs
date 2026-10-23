import assert from "node:assert/strict";
import {
    verifyFilteredRequestMap,
    verifyShellNavigationHistory,
} from "./enterprise-shell-navigation-browser.mjs";

export async function checkShellNavigation(page) {
    const tenant = "synthetic-enterprise";
    const command = (body) =>
        page.evaluate(
            async ({ tenant, body }) => {
                const response = await fetch(
                    "/enterprise/commands?tenant=" + tenant,
                    {
                        method: "POST",
                        credentials: "same-origin",
                        headers: {
                            Accept: "application/json",
                            "Content-Type": "application/json",
                            "X-CSRF-TOKEN": document.querySelector(
                                "meta[name=csrf-token]",
                            ).content,
                        },
                        body: JSON.stringify(body),
                    },
                );
                const data = await response.json();
                if (!response.ok) throw new Error(JSON.stringify(data));
                return data;
            },
            {
                tenant,
                body: {
                    id: crypto.randomUUID(),
                    request_id: crypto.randomUUID(),
                    expected_version: 0,
                    ...body,
                },
            },
        );
    const area = (
        await command({
            action: "workspace.create",
            title: "Synthetic navigation workspace",
            visibility: "department_only",
        })
    ).id;
    const other = (
        await command({
            action: "workspace.create",
            title: "Synthetic alternate navigation workspace",
            visibility: "department_only",
        })
    ).id;
    const project = (
        await command({
            action: "project.create",
            area_id: area,
            title: "Synthetic retained navigation project",
        })
    ).id;
    const saveBuilding=async number=>page.evaluate(async({area,number,tenant})=>{
        const response=await fetch('/enterprise/resources/commands?tenant='+tenant,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({action:'location.save',area_id:area,id:crypto.randomUUID(),request_id:crypto.randomUUID(),expected_version:0,title:'Synthetic source-map reference',catalog_map_number:number,level:'facility'})});const result=await response.json();if(!response.ok)throw new Error(JSON.stringify(result));return result;
    },{area,number,tenant});
    const includedBuilding=await saveBuilding('52'),excludedBuilding=await saveBuilding('2');
    const included = (
        await command({
            action: "request.create",
            area_id: area,
            title: "Synthetic filtered map inspection",
            location_id: includedBuilding.id,
            location_version: includedBuilding.version,
        })
    ).id;
    const excluded = (
        await command({
            action: "request.create",
            area_id: area,
            title: "Synthetic separate map task",
            location_id: excludedBuilding.id,
            location_version: excludedBuilding.version,
        })
    ).id;
    await verifyFilteredRequestMap(page, {
        synthetic: true,
        areaId: area,
        includedId: included,
        excludedId: excluded,
        query: "filtered map",
    });
    await verifyShellNavigationHistory(page, {
        synthetic: true,
        areaId: area,
        projectId: project,
        otherAreaId: other,
    });
    assert.equal(
        await page.evaluate(
            () => document.documentElement.scrollWidth > innerWidth + 1,
        ),
        false,
    );
    console.log(
        "PASS real HTTP shell navigation: same filtered authorized rows on map/list, selected marker, Back/Forward workspace/view/selection, retained unsent composer and reload. Explicit saved-building references use printed source-map positions, not GPS.",
    );
}
