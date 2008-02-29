(*

Copyright (c) 2008 The Regents of the University of California
All rights reserved.

Authors: Luca de Alfaro, Ian Pye 

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice,
this list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright notice,
this list of conditions and the following disclaimer in the documentation
and/or other materials provided with the distribution.

3. The names of the contributors may not be used to endorse or promote
products derived from this software without specific prior written
permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
POSSIBILITY OF SUCH DAMAGE.

 *)

(** This class provides a handle for accessing the database in the on-line 
    implementation.
    I don't know how it is created; most likely, it would take a database 
    name and password, or something like that, to connect to the db. *)
class db : 
  ?? -> 

  object

    (** [read_text_split_version page_id] reads the version of Text.ml that has 
	been used to split a revision in words, and returns it. *)
    method read_text_split_version : int -> string

    (** [write_text_split_version page_id] writes the version of Text.ml that has 
	been used to split a revision in words, and returns it. *)
    method read_text_split_version : int -> string -> unit 

    (** [read_edit_diff revid1 revid2] reads from the database the edit list 
	from the (live) text of revision [revid1] to revision [revid2]. *)
    method read_edit_diff : int -> int -> (Editlist.edit list)

    (** [write_edit_diff revid1 revid2 elist] writes to the database the edit list 
	[elist] from the (live) text of revision [revid1] to revision [revid2]. *)
    method write_edit_diff : int -> int -> (Editlist.edit list) -> unit

  end

