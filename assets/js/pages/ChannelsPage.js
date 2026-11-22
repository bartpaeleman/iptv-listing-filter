import { BaseComponent } from '../core/BaseComponent.js';
import { debounce } from '../core/Debounce.js';
import { GroupList } from '../components/GroupList.js';
import { ChannelTable } from '../components/ChannelTable.js';

export class ChannelsPage extends BaseComponent {
    constructor(container, props) {
        super(container, props);
        this.selectedGroupId = null;
        this.channelSearch = '';
        this.groupList = null;
        this.channelTable = null;
    }

    async load() {
        if (!this.container.dataset.mounted) {
            this.renderShell();
            this.container.dataset.mounted = '1';
        }
        const groups = await this.props.api.get('groups');
        this.groupList.setState({ groups, selectedGroupId: this.selectedGroupId });
        await this.loadChannels();
    }

    async loadChannels() {
        const params = { search: this.channelSearch, limit: 150 };
        if (this.selectedGroupId) params.group_id = this.selectedGroupId;
        const data = await this.props.api.get('channels', params);
        this.channelTable.setState({ channels: data.items });
    }

    renderShell() {
        this.container.innerHTML = `
            <div class="split-view">
                <aside class="card groups-pane">
                    <div class="card-header">
                        <h2>Groepen</h2>
                        <div class="btn-group">
                            <button class="btn btn-sm" id="enableAllGroupsBtn">Alles Aan</button>
                            <button class="btn btn-sm" id="disableAllGroupsBtn">Alles Uit</button>
                        </div>
                    </div>
                    <input type="search" id="groupSearchInput" placeholder="Filter groepen...">
                    <div id="groupsListRoot" class="groups-list"></div>
                </aside>
                <div class="card channels-pane">
                    <div class="card-header">
                        <h2 id="selectedGroupTitle">Alle Kanalen</h2>
                        <div class="btn-group">
                            <button class="btn btn-sm" id="bulkEnableChannelsBtn">Selectie Aan</button>
                            <button class="btn btn-sm" id="bulkDisableChannelsBtn">Selectie Uit</button>
                        </div>
                    </div>
                    <div class="toolbar">
                        <input type="search" id="channelSearchInput" placeholder="Zoek kanaalnaam...">
                    </div>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="selectAllChannels"></th>
                                    <th>Aan</th>
                                    <th>#</th>
                                    <th>Naam (Origineel / Custom)</th>
                                    <th>Groep</th>
                                </tr>
                            </thead>
                            <tbody id="channelsTableRoot"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;

        this.groupList = new GroupList('#groupsListRoot', {
            onSelect: (id, name) => {
                this.selectedGroupId = id;
                document.getElementById('selectedGroupTitle').textContent = name;
                this.groupList.setState({ selectedGroupId: id });
                this.loadChannels();
            },
            onToggle: async (id, checked) => {
                await this.props.api.post('update_group', { id, is_enabled: checked ? 1 : 0 });
                this.props.toast.show('Groep bijgewerkt');
                this.load();
            }
        });

        this.channelTable = new ChannelTable('#channelsTableRoot', {
            onUpdate: async (id, payload) => {
                await this.props.api.post('update_channel', { id, ...payload });
                this.props.toast.show('Kanaal bijgewerkt');
            }
        });

        document.getElementById('groupSearchInput')?.addEventListener('input', debounce(e => {
            this.groupList.setState({ filter: e.target.value });
        }, 200));

        document.getElementById('channelSearchInput')?.addEventListener('input', debounce(e => {
            this.channelSearch = e.target.value;
            this.loadChannels();
        }, 300));

        document.getElementById('enableAllGroupsBtn')?.addEventListener('click', async () => {
            await this.props.api.post('bulk_groups', { is_enabled: 1 });
            this.load();
        });

        document.getElementById('disableAllGroupsBtn')?.addEventListener('click', async () => {
            await this.props.api.post('bulk_groups', { is_enabled: 0 });
            this.load();
        });

        document.getElementById('selectAllChannels')?.addEventListener('change', ev => {
            document.querySelectorAll('.ch-select').forEach(cb => { cb.checked = ev.target.checked; });
        });

        const bulkChannels = async enable => {
            const ids = this.channelTable.getSelectedIds();
            if (ids.length === 0) return this.props.toast.show('Selecteer eerst kanalen.');
            await this.props.api.post('bulk_channels', { ids, is_enabled: enable ? 1 : 0 });
            this.loadChannels();
        };
        document.getElementById('bulkEnableChannelsBtn')?.addEventListener('click', () => bulkChannels(true));
        document.getElementById('bulkDisableChannelsBtn')?.addEventListener('click', () => bulkChannels(false));
    }
}
