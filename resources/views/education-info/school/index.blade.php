@extends('layouts.dashboard')
@section('title', 'Schools')

@section('content')
    <!-- Modal -->
    <div class="modal fade" id="educationModal" tabindex="-1" role="dialog" aria-labelledby="educationModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="educationModalLabel"></h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <!-- Content loaded by Ajax -->
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="schoolRadiusModal" tabindex="-1" role="dialog"
        aria-labelledby="schoolRadiusModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="schoolRadiusModalLabel">Schools Within 200m</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form id="school-radius-form" enctype="multipart/form-data">
                        @csrf
                        <div class="form-group">
                            <label>Search location</label>
                            <div>
                                <div class="custom-control custom-radio custom-control-inline">
                                    <input type="radio" id="radius-source-bin" name="source_type"
                                        value="bin" class="custom-control-input" checked>
                                    <label class="custom-control-label" for="radius-source-bin">BIN Number</label>
                                </div>
                                <div class="custom-control custom-radio custom-control-inline">
                                    <input type="radio" id="radius-source-kml" name="source_type"
                                        value="kml" class="custom-control-input">
                                    <label class="custom-control-label" for="radius-source-kml">KML File</label>
                                </div>
                            </div>
                        </div>
                        <div class="form-group" id="radius-bin-group">
                            <label for="radius-bin">BIN Number</label>
                            <input type="text" class="form-control" id="radius-bin" name="bin"
                                placeholder="Enter building BIN">
                        </div>
                        <div class="form-group d-none" id="radius-kml-group">
                            <label for="radius-kml">KML File</label>
                            <input type="file" class="form-control-file" id="radius-kml" name="kml"
                                accept=".kml,application/vnd.google-earth.kml+xml">
                            <small class="form-text text-muted">Upload one point or polygon KML file (maximum 1 MB).</small>
                        </div>
                        <div id="school-radius-error" class="alert alert-danger d-none" role="alert"></div>
                        <button type="submit" class="btn btn-info" id="school-radius-submit">
                            Check Schools
                        </button>
                    </form>

                    <div id="school-radius-results" class="mt-4 d-none">
                        <h6 id="school-radius-summary"></h6>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>School</th>
                                        <th>BIN</th>
                                        <th>Distance</th>
                                    </tr>
                                </thead>
                                <tbody id="school-radius-results-body"></tbody>
                            </table>
                        </div>
                        <div class="text-right mt-3">
                            <button type="button" class="btn btn-success btn-sm" id="school-radius-export">
                                <i class="fas fa-file-csv"></i> Export CSV
                            </button>
                            <a href="#" class="btn btn-info btn-sm" id="school-radius-map">
                                <i class="fas fa-map-marker-alt"></i> View on Map
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            @can('Add Education')
                <a href="{{ route('education.school.create') }}" class="btn btn-info">Add School</a>
            @endcan
            @can('View Nearest Schools On Map')
                <button type="button" class="btn btn-info" data-toggle="modal" data-target="#schoolRadiusModal">
                    <i class="fas fa-search-location"></i> Check Schools Within 200m
                </button>
            @endcan
            @can('Export Education')
                <a href="{{-- {{ route('education.export') }} --}}" class="btn btn-info">Export CSV</a>
            @endcan
            @can('Export Education')
                <a href="#" id="export-kml" class="btn btn-info">Export KML</a>
            @endcan

            <a href="#" class="btn btn-info float-right" data-toggle="collapse"
                data-target="#filterCollapse" aria-expanded="true" aria-controls="filterCollapse">
                Show Filter
            </a>
        </div>
        <div class="card-body">
            <div class="collapse" id="filterCollapse">
                <form id="filter-form" class="mb-4">
                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label>Institution Name</label>
                            <input type="text" class="form-control" id="institution_name" placeholder="Name">
                        </div>
                        <div class="form-group col-md-3">
                            <label>Type</label>
                            <select class="form-control" id="institution_type">
                                <option value="">Select Type</option>
                                @foreach ($institution_types as $key => $type)
                                    <option value="{{ $key }}">{{ $type }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label>Ward</label>
                            <select class="form-control" id="ward_number">
                                <option value="">Select Ward</option>
                                @foreach ($wards as $key => $ward)
                                    <option value="{{ $key }}">{{ $ward }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label>Ownership</label>
                            <select class="form-control" id="ownership">
                                <option value="">Public / Private</option>
                                <option value="Public">Public</option>
                                <option value="Private">Private</option>
                            </select>
                        </div>
                    </div>
                    <div class="text-right">
                        <button type="submit" class="btn btn-info">Filter</button>
                        <button type="reset" id="reset-filter" class="btn btn-info">Reset</button>
                    </div>
                </form>
            </div>

            <div style="overflow:auto; width:100%;">
                <table id="education-table" class="table table-bordered table-striped" width="100%">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Ward</th>
                            <th>Type</th>
                            <th>Ownership</th>
                            <th>Contact Person</th>
                            <th>Phone</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
@stop

@push('scripts')
<script>
    $(function() {
        var schoolRadiusResults = [];
        var schoolRadiusSearchContext = null;

        function escapeHtml(value) {
            return $('<div>').text(value == null ? '' : value).html();
        }

        function csvValue(value) {
            return '"' + String(value == null ? '' : value).replace(/"/g, '""') + '"';
        }

        $('input[name="source_type"]').on('change', function() {
            var useKml = this.value === 'kml';
            $('#radius-bin-group').toggleClass('d-none', useKml);
            $('#radius-kml-group').toggleClass('d-none', !useKml);
            $('#radius-bin').prop('required', !useKml);
            $('#radius-kml').prop('required', useKml);
            $('#school-radius-error, #school-radius-results').addClass('d-none');
        }).filter(':checked').trigger('change');

        $('#schoolRadiusModal').on('hidden.bs.modal', function() {
            $('#school-radius-form')[0].reset();
            $('#radius-source-bin').prop('checked', true).trigger('change');
            $('#school-radius-results-body').empty();
            schoolRadiusResults = [];
            schoolRadiusSearchContext = null;
        });

        $('#school-radius-export').on('click', function() {
            if (!schoolRadiusResults.length) {
                return;
            }

            var rows = [];
            if (schoolRadiusSearchContext.type === 'bin') {
                rows.push(['Search BIN', schoolRadiusSearchContext.reference]);
            } else {
                rows.push(['Search KML', schoolRadiusSearchContext.reference]);
            }
            rows.push(['Radius', '200 m']);
            rows.push([]);
            rows.push(['School', 'BIN', 'Distance (m)']);

            schoolRadiusResults.forEach(function(school) {
                rows.push([
                    school.name || 'School',
                    school.bin || '',
                    school.distance_m
                ]);
            });

            var csv = rows.map(function(row) {
                return row.map(csvValue).join(',');
            }).join('\r\n');
            var blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement('a');
            var reference = schoolRadiusSearchContext.reference || schoolRadiusSearchContext.type.toUpperCase();

            link.href = URL.createObjectURL(blob);
            link.download = 'schools-within-200m-' + reference.replace(/[^a-z0-9_-]+/gi, '-') + '.csv';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(link.href);
        });

        $('#school-radius-form').on('submit', function(e) {
            e.preventDefault();

            var $button = $('#school-radius-submit');
            var $error = $('#school-radius-error');
            var sourceType = $('input[name="source_type"]:checked').val();
            var searchReference = sourceType === 'bin'
                ? $('#radius-bin').val().trim()
                : ($('#radius-kml')[0].files[0] ? $('#radius-kml')[0].files[0].name : 'KML');
            $error.addClass('d-none').text('');
            $('#school-radius-results').addClass('d-none');
            $button.prop('disabled', true).text('Checking...');

            $.ajax({
                url: '{{ route('maps.school-radius-search') }}',
                method: 'POST',
                data: new FormData(this),
                processData: false,
                contentType: false,
                dataType: 'json'
            }).done(function(data) {
                var schools = Array.isArray(data.schools) ? data.schools : [];
                schoolRadiusResults = schools;
                schoolRadiusSearchContext = {
                    type: sourceType,
                    reference: searchReference
                };
                var rows = '';

                if (schools.length === 0) {
                    rows = '<tr><td colspan="3" class="text-center">No schools found within 200m.</td></tr>';
                    $('#school-radius-summary').text('No schools found within 200m');
                } else {
                    schools.forEach(function(school) {
                        rows += '<tr>' +
                            '<td>' + escapeHtml(school.name || 'School') + '</td>' +
                            '<td>' + escapeHtml(school.bin || '-') + '</td>' +
                            '<td>' + escapeHtml(school.distance_m) + ' m</td>' +
                            '</tr>';
                    });
                    $('#school-radius-summary').text(schools.length +
                        (schools.length === 1 ? ' school found' : ' schools found'));
                }

                $('#school-radius-results-body').html(rows);
                $('#school-radius-export').prop('disabled', schools.length === 0);
                $('#school-radius-map').attr('href', '{{ url('maps') }}?' + $.param({
                    action: 'school-radius',
                    lat: data.center_lat,
                    long: data.center_long
                }));
                $('#school-radius-results').removeClass('d-none');
            }).fail(function(xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : 'Unable to check schools. Please try again.';
                $error.text(message).removeClass('d-none');
            }).always(function() {
                $button.prop('disabled', false).text('Check Schools');
            });
        });

        var table = $('#education-table').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '{{ route('education.school.data') }}',
                type: 'GET',              // be explicit
                dataType: 'json',         // expect JSON
                data: function (d) {
                    d.institution_name = $('#institution_name').val();
                    d.institution_type = $('#institution_type').val();
                    d.ward_number      = $('#ward_number').val();
                    d.ownership        = $('#ownership').val();
                },
                error: function (xhr, error, thrown) {
                    console.error('DataTables Ajax error:', xhr.status, error, thrown);
                    console.error(xhr.responseText); // this will show your Laravel error/HTML
                    alert('Failed to load education data. Check console for details.');
                }
            },
            columns: [
                { data: 'custom_school_id',      name: 'custom_school_id' },
                { data: 'name',                  name: 'name' },
                { data: 'ward_no',               name: 'ward_no' },
                { data: 'type',                  name: 'type' },                 // Type
                { data: 'ownership',             name: 'ownership' },            // Ownership
                { data: 'contact_person_name',   name: 'contact_person_name' },
                { data: 'contact_person_number', name: 'contact_person_number' },
                { data: 'action',                name: 'action', orderable: false, searchable: false }
            ],
            order: [[0, 'asc']]
        });

        $('#filter-form').on('submit', function(e) {
            e.preventDefault();
            table.draw();
        });

        $('#reset-filter').on('click', function() {
            $('#filter-form')[0].reset();
            table.draw();
        });

        $(document).on('click', '.show-institution', function() {
            var id = $(this).data('id');
            $.ajax({
                url: '{{ url("education") }}/' + id,
                type: 'GET',
                success: function(data) {
                    $('#educationModal .modal-title').text(data.name);
                    $('#educationModal .modal-body').html(data.detailsHtml);
                    // $('#educationModal').modal('show');
                },
                error: function(xhr) {
                    console.error('Show institution error:', xhr.responseText);
                }
            });
        });
    });
</script>
@endpush

